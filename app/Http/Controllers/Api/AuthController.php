<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected PermissionService $permissions,
        protected AccessScopeService $accessScope,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $identifier = $request->filled('email') ? $request->input('email') : $request->input('username');

        $user = $identifier === null
            ? null
            : User::where('email', $identifier)->first()
                ?? User::where('username', $identifier)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        if (! $user->is_active || ! $user->employee?->is_active) {
            throw ValidationException::withMessages(['username' => 'Account is deactivated.']);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /** Self-service: update own email/payroll and change password. */
    public function updateMe(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        $validated = $request->validate([
            'email' => ['sometimes', 'nullable', 'email', 'max:120', Rule::unique('employees', 'email')->ignore($employee?->id)],
            'payroll_no' => ['sometimes', 'nullable', 'string', 'max:40', Rule::unique('employees', 'payroll_no')->ignore($employee?->id)],
            'current_password' => ['required_with:new_password'],
            'new_password' => ['sometimes', 'string', 'min:8', 'confirmed'],
        ]);

        if ($request->filled('current_password') && ! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
        }

        if ($request->filled('new_password')) {
            $user->update(['password' => $validated['new_password']]);
        }

        if ($employee && ($request->has('email') || $request->has('payroll_no'))) {
            if ($request->has('email')) {
                $employee->email = $validated['email'];
            }
            if ($request->has('payroll_no')) {
                $employee->payroll_no = $validated['payroll_no'];
            }
            $employee->save();
        }

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    protected function userPayload(User $user): array
    {
        $employee = $user->employee;

        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'employee' => $employee ? [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'initials' => $employee->initials,
                'payroll_no' => $employee->payroll_no,
                'gender' => $employee->gender,
                'position_id' => $employee->position_id,
                'department_id' => $employee->department_id,
                'zone_id' => $employee->zone_id,
                'hire_date' => $employee->hire_date?->toDateString(),
            ] : null,
            'roles' => $this->permissions->rolesFor($user),
            'permissions' => $this->permissions->codesFor($user),
            /*
             * Server-resolved data-visibility capabilities. The frontend
             * derives supervisorMode from this payload instead of a local
             * toggle, so "supervisor view" can never claim more than the
             * backend will actually return. Scopes are advisory (UX filtering
             * only) — the backend re-checks every request.
             */
            'access' => [
                'can_view_cross_zone' => $this->accessScope->canViewAllZones($user, AccessScopeService::REQUESTS),
                'can_view_cross_zone_staff' => $this->accessScope->canViewAllZones($user, AccessScopeService::STAFF),
                'can_view_cross_zone_reports' => $this->accessScope->canViewAllZones($user, AccessScopeService::REPORTS),
                'request_scope' => $this->accessScope->forUser($user, AccessScopeService::REQUESTS)->toArray(),
                'staff_scope' => $this->accessScope->forUser($user, AccessScopeService::STAFF)->toArray(),
            ],
        ];
    }
}
