<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\AccessScopeService;
use App\Services\PolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class EligibilityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        $policy = resolve(PolicyService::class);
        $types = LeaveType::orderBy('name')->get();

        $rows = $types->map(function (LeaveType $t) use ($employee, $policy) {
            $pol = $policy->currentPolicy($t->id);
            $tenure = $policy->tenureMonths($employee->hire_date);
            $bal = $policy->balanceFor($employee->id, $t->id);

            $policyOk = $pol !== null;
            $tenureOk = ! $policyOk || $tenure >= (int) ($pol->min_tenure_months ?? 0);
            $noticeOk = ! $policyOk || ($pol->min_notice_days ?? 0) === 0;
            $docsOk = ! $policyOk || ! (bool) $pol->requires_documents;
            $balanceOk = $bal['remaining'] > 0;

            $blockers = [];
            if (! $policyOk) {
                $blockers[] = 'No active policy configured.';
            }
            if (! $tenureOk) {
                $blockers[] = "Minimum {$pol->min_tenure_months}-month service required; you have {$tenure}.";
            }
            if (! $noticeOk) {
                $blockers[] = "Minimum {$pol->min_notice_days}-day advance notice required.";
            }
            if (! $docsOk) {
                $blockers[] = 'Supporting documents required.';
            }
            if (! $balanceOk) {
                $blockers[] = 'No remaining balance for this leave type.';
            }
            if (! $employee->is_active) {
                $blockers[] = 'Employee is not active.';
            }

            return [
                'leave_type_id' => $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'eligible' => $policyOk && $tenureOk && $balanceOk && $employee->is_active,
                'policy_ok' => $policyOk,
                'tenure_ok' => $tenureOk,
                'balance_remaining' => $bal['remaining'],
                'max_days_per_request' => $pol ? (int) $pol->max_days_per_request : 0,
                'min_notice_days' => $pol ? (int) $pol->min_notice_days : 0,
                'blockers' => $blockers,
            ];
        });

        $org = $this->orgSummary($request);

        return response()->json(['data' => $rows, 'org' => $org]);
    }

    protected function orgSummary(Request $request): array
    {
        $policy = resolve(PolicyService::class);
        $types = LeaveType::pluck('code')->all();
        $leaveTypes = LeaveType::all()->keyBy('id');

        // The summary used to aggregate every active employee in the organisation
        // and return it to any authenticated caller. A Pemba clerk, who may only
        // ever see their own zone, was told the headcount and eligible totals for
        // every department in every zone. It is now computed over the same
        // population the caller is allowed to read.
        $scope = resolve(AccessScopeService::class)->forUser($request->user(), AccessScopeService::REPORTS);

        $employees = Employee::where('is_active', true)->get();
        if (! $scope->canViewAllZones) {
            $ownId = $request->user()->employee?->id;
            $scopeService = resolve(AccessScopeService::class);

            // A zone grant reaches every department in it, a department grant
            // only that department, and NO grant reaches nobody but yourself
            // (mirroring the request-scope rule). The old filter treated an
            // empty department list as "all departments", so a zone-only head
            // — and every plain employee — got organisation-wide totals while
            // the payload still claimed "scoped => true".
            $employees = $employees->filter(fn (Employee $e) => $e->id === $ownId
                || $scopeService->coversEmployee($scope, $e))->values();
        }

        $perDepartment = [];
        foreach ($employees->groupBy('department_id') as $deptId => $group) {
            $perDepartment[] = [
                'department_id' => $deptId,
                'eligible' => $group->filter(fn (Employee $e) => $this->anyTypeEligible($e, $policy, $leaveTypes))->count(),
                'total' => $group->count(),
            ];
        }

        $perZone = [];
        foreach ($employees->groupBy('zone_id') as $zoneId => $group) {
            $perZone[] = [
                'zone_id' => $zoneId,
                'eligible' => $group->filter(fn (Employee $e) => $this->anyTypeEligible($e, $policy, $leaveTypes))->count(),
                'total' => $group->count(),
            ];
        }

        return [
            'scoped' => ! $scope->canViewAllZones,
            'total_eligible' => $employees->filter(fn (Employee $e) => $this->anyTypeEligible($e, $policy, $leaveTypes))->count(),
            'total_employees' => $employees->count(),
            'per_department' => $perDepartment,
            'per_zone' => $perZone,
        ];
    }

    /**
     * @param  Collection<int,LeaveType>  $leaveTypes
     */
    protected function anyTypeEligible(Employee $employee, PolicyService $policy, $leaveTypes): bool
    {
        $tenure = $policy->tenureMonths($employee->hire_date);

        foreach ($leaveTypes as $leaveType) {
            $pol = $policy->currentPolicy($leaveType->id);
            if (! $pol) {
                continue;
            }
            if ($tenure >= (int) ($pol->min_tenure_months ?? 0)) {
                return true;
            }
        }

        return false;
    }
}
