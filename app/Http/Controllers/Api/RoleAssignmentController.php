<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeApprovalRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Role assignments (spec §15: /api/role-assignments).
 *
 * The system already stores approval authority in `employee_approval_roles`
 * (seeded grants, org head appointments, WorkflowResolver lookups). Rather
 * than inventing a parallel table, this controller is a thin, backward-
 * compatible CRUD face over that table: the same rows the resolver reads,
 * exposed under the spec's endpoint name.
 *
 * Permissions reuse the existing admin family (`admin.view` / `admin.update`)
 * because that is exactly the holder set of the org grant routes that already
 * write this table. Introducing new `role_assignments.*` codes would require
 * catalog + matrix changes for no additional capability.
 */
class RoleAssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmployeeApprovalRole::with(['employee', 'approvalRole']);

        if ($employeeId = $request->input('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        if ($roleId = $request->input('approval_role_id', $request->input('role_id'))) {
            $query->where('approval_role_id', $roleId);
        }

        if ($departmentId = $request->input('department_id', $request->input('organizational_unit_id'))) {
            $query->where('organizational_unit_id', $departmentId);
        }

        if ($zoneId = $request->input('zone_id')) {
            $query->where('zone_id', $zoneId);
        }

        $today = now()->toDateString();
        $status = (string) $request->input('status', '');
        if ($status === 'active') {
            $query->where(function ($q) use ($today) {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $today);
            })->where(function ($q) use ($today) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
            });
        } elseif ($status === 'expired') {
            $query->whereNotNull('effective_to')->whereDate('effective_to', '<', $today);
        }

        $query->orderByDesc('effective_from')->orderBy('employee_id');

        if (! $request->has('page') && ! $request->has('per_page')) {
            return response()->json(['data' => $query->get()]);
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));
        $paginator = $query->paginate($perPage, ['*'], 'page', max(1, (int) $request->input('page', 1)));

        return response()->json([
            'data' => collect($paginator->items())->values(),
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $this->assertNoOverlap($data);

        $assignment = EmployeeApprovalRole::create([
            'id' => 'ear_' . Str::lower(Str::random(16)),
            ...$data,
            'source' => $data['source'] ?? 'manual_grant',
        ]);

        return response()->json(['data' => $assignment->load(['employee', 'approvalRole'])], 201);
    }

    public function update(Request $request, EmployeeApprovalRole $roleAssignment): JsonResponse
    {
        $data = $this->validated($request, $roleAssignment);

        $candidate = array_merge([
            'employee_id' => $roleAssignment->employee_id,
            'approval_role_id' => $roleAssignment->approval_role_id,
            'organizational_unit_id' => $roleAssignment->organizational_unit_id,
            'zone_id' => $roleAssignment->zone_id,
            'effective_from' => $roleAssignment->effective_from?->toDateString(),
            'effective_to' => $roleAssignment->effective_to?->toDateString(),
        ], $data);

        $this->assertNoOverlap($candidate, $roleAssignment->id);

        $roleAssignment->update($data);

        return response()->json(['data' => $roleAssignment->fresh(['employee', 'approvalRole'])]);
    }

    public function destroy(EmployeeApprovalRole $roleAssignment): JsonResponse
    {
        $roleAssignment->delete();

        return response()->json(['message' => 'Ugawaji wa nafasi umeondolewa.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?EmployeeApprovalRole $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        $data = $request->validate([
            'employee_id' => [$required, Rule::exists('employees', 'id')],
            'approval_role_id' => [$required, Rule::exists('approval_roles', 'id')],
            // Spec-friendly alias for approval_role_id.
            'role_id' => ['sometimes', Rule::exists('approval_roles', 'id')],
            'organizational_unit_id' => ['sometimes', 'nullable', Rule::exists('departments', 'id')],
            'department_id' => ['sometimes', 'nullable', Rule::exists('departments', 'id')],
            'zone_id' => ['sometimes', 'nullable', Rule::exists('zones', 'id')],
            'is_primary' => ['sometimes', 'boolean'],
            'effective_from' => [$required, 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],
            'source' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        if (! isset($data['approval_role_id']) && isset($data['role_id'])) {
            $data['approval_role_id'] = $data['role_id'];
        }
        if (! isset($data['organizational_unit_id']) && isset($data['department_id'])) {
            $data['organizational_unit_id'] = $data['department_id'];
        }

        unset($data['role_id'], $data['department_id']);

        if ($existing) {
            $data = array_filter($data, fn ($v, $k) => $v !== null || in_array($k, ['organizational_unit_id', 'zone_id', 'effective_to'], true), ARRAY_FILTER_USE_BOTH);
        }

        return $data;
    }

    /**
     * Two overlapping grants for the same employee + role + placement make the
     * "who stands in this role" answer non-deterministic, so reject them the
     * same way the workflow overlap guard rejects ambiguous chains.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function assertNoOverlap(array $candidate, ?string $excludeId = null): void
    {
        $existing = DB::table('employee_approval_roles')
            ->where('employee_id', $candidate['employee_id'])
            ->where('approval_role_id', $candidate['approval_role_id'])
            ->when($excludeId, fn ($q) => $q->where('id', '<>', $excludeId))
            ->get();

        foreach ($existing as $row) {
            $sameUnit = (string) ($row->organizational_unit_id ?? '') === (string) ($candidate['organizational_unit_id'] ?? '');
            $sameZone = (string) ($row->zone_id ?? '') === (string) ($candidate['zone_id'] ?? '');
            if (! $sameUnit || ! $sameZone) {
                continue;
            }

            $aFrom = $candidate['effective_from'] ?? null;
            $aTo = $candidate['effective_to'] ?? null;
            $bFrom = $row->effective_from;
            $bTo = $row->effective_to;

            $overlaps = strcmp((string) ($aFrom ?: '1970-01-01'), $bTo ?: '9999-12-31') <= 0
                && strcmp((string) ($bFrom ?: '1970-01-01'), $aTo ?: '9999-12-31') <= 0;

            if ($overlaps) {
                throw ValidationException::withMessages([
                    'approval_role_id' => 'This employee already holds this role for the same scope over an overlapping period.',
                ]);
            }
        }
    }
}
