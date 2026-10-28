<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDelegationRequest;
use App\Models\Delegation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DelegationController extends Controller
{
    /** A user manages delegations for roles they actually hold. */
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        return response()->json([
            'data' => Delegation::with(['delegator', 'delegate', 'role'])
                ->where('delegator_employee_id', $employee->id)
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function store(StoreDelegationRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        // Must currently hold the role being delegated (blueprint §2.1a).
        //
        // roles() is the raw relation, so it ignores employee_roles.active, the
        // effective window and roles.active. A grant that was withdrawn, has
        // lapsed, or sits on an archived role therefore still looked held and a
        // lapsed break-glass authority could be handed to someone else. Permission
        // Service::activeAssignments() already encodes all four conditions; use it.
        $holdsRole = $employee->roles()
            ->where('role_id', $request->role_id)
            ->where('employee_roles.active', true)
            ->where(function ($q) {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now());
            })
            ->where('roles.active', true)
            ->exists();
        if (! $holdsRole) {
            throw ValidationException::withMessages(['role_id' => 'You can only delegate a role you currently hold.']);
        }

        if ($employee->id === $request->delegate_employee_id) {
            throw ValidationException::withMessages(['delegate_employee_id' => 'You cannot delegate to yourself.']);
        }

        $id = 'del'.(Delegation::count() + 1);
        $delegation = Delegation::create([
            'id' => $id,
            'delegator_employee_id' => $employee->id,
            'delegate_employee_id' => $request->delegate_employee_id,
            'role_id' => $request->role_id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'authorized_by' => $employee->id,
            'active' => true,
        ]);

        return response()->json(['data' => $delegation->load(['delegator', 'delegate', 'role'])], 201);
    }

    public function revoke(Request $request, Delegation $delegation): JsonResponse
    {
        if ($delegation->delegator_employee_id !== $request->user()->employee?->id) {
            return response()->json(['message' => 'You can only revoke your own delegations.'], 403);
        }
        $delegation->update(['active' => false]);

        return response()->json(['data' => $delegation->refresh()]);
    }
}
