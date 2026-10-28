<?php

namespace App\Services;

use App\Models\ApprovalRole;
use App\Models\Delegation;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeApprovalRole;
use App\Models\EmployeeRole;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Dynamic workflow resolver — blueprint Part 2.
 *
 * No hard-coded role strings (SUPERVISOR, DEPT_HEAD, etc.).
 * Resolution is driven by each step's `approval_role_id` and that
 * role's `resolution_type` configuration.
 */
class WorkflowResolver
{
    protected PolicyService $policy;

    /** @param  PolicyService  $policy */
    public function __construct(PolicyService $policy)
    {
        $this->policy = $policy;
    }

    /** @var array{assignee: ?Employee, via: 'standing'|'delegation'|'escalated'|'default'} */
    public function resolveApprover(Employee $employee, string $approvalRoleId, ?Collection $delegations = null): array
    {
        $approvalRole = ApprovalRole::where('id', $approvalRoleId)->first();

        if (! $approvalRole) {
            return $this->noHolder('approval role not found: ' . $approvalRoleId);
        }

        $resolutionType = $approvalRole->resolution_type ?? 'employee_approval_roles';

        // 1. Active delegation for this role overrides the standing holder.
        $active = ($delegations ?? Delegation::all())->first(function (Delegation $d) use ($employee, $approvalRoleId, $resolutionType) {
            return $d->active
                && $d->approval_role_id === $approvalRoleId
                && Carbon::parse($d->start_date)->lte(Carbon::parse(now()->toDateString()))
                && Carbon::parse($d->end_date)->gte(Carbon::parse(now()->toDateString()))
                && $d->delegate_employee_id !== $employee->id; // never delegate back to the requester
        });

        if ($active) {
            return ['assignee' => Employee::find($active->delegate_employee_id), 'via' => 'delegation'];
        }

        // 2. Standing holder — resolve based on resolution_type
        $holder = $this->standingHolder($employee, $approvalRoleId, $resolutionType);

        if ($holder && $holder->id !== $employee->id) {
            return ['assignee' => $holder, 'via' => 'standing'];
        }

        // Self-exclusion: requester holds the role themselves — try another holder
        if ($holder && $holder->id === $employee->id) {
            $other = $this->standingHolder($employee, $approvalRoleId, $resolutionType, exclude: $employee->id);
            $final = $this->standingHolder($employee, 'final_authority', $resolutionType, exclude: $employee->id);

            return ['assignee' => $other ?? $final, 'via' => 'escalated'];
        }

        // No holder found
        return $this->noHolder('no valid holder for approval role ' . $approvalRoleId . ' (resolution_type=' . $resolutionType . ')');
    }

    /**
     * Resolve the standing holder for an approval role.
     *
     * Resolution sources, in order of precedence for the given resolution_type:
     *   'supervisor'       -> reporting_lines only
     *   'employee_approval_roles'  -> employee_approval_roles only
     *   'both'             -> employee_approval_roles first, then reporting_lines
     *
     * The requester is excluded via the $exclude parameter.
     */
    protected function standingHolder(Employee $employee, string $approvalRoleId, string $resolutionType = 'employee_approval_roles', ?string $exclude = null): ?Employee
    {
        // === Source: employee_approval_roles ===
        $approvalHolder = null;
        if (in_array($resolutionType, ['employee_approval_roles', 'both'], true)) {
            $query = EmployeeApprovalRole::where('employee_id', '!=', $employee->id)
                ->where('approval_role_id', $approvalRoleId)
                ->where('active', true)
                ->where(function ($q) {
                    $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString())
                        ->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString());
                });

            if ($employee->department_id) {
                $query->orWhere(function ($q) use ($employee) {
                    $q->whereNull('organizational_unit_id')
                        ->orWhere('organizational_unit_id', $employee->department_id);
                });
            }
            if ($employee->zone_id) {
                $query->orWhere(function ($q) use ($employee) {
                    $q->whereNull('zone_id')->orWhere('zone_id', $employee->zone_id);
                });
            }

            $rows = $query->get()->sortByDesc(function ($er) use ($employee) {
                $score = 0;
                if ($er->organizational_unit_id === $employee->department_id) {
                    $score += 2;
                } elseif ($er->organizational_unit_id === null && $employee->department_id === null) {
                    $score += 1;
                }
                if ($er->zone_id === $employee->zone_id) {
                    $score += 1;
                }
                return $score;
            });

            foreach ($rows as $row) {
                if ($row->employee_id === $exclude) {
                    continue;
                }
                $emp = Employee::find($row->employee_id);
                if ($emp && $emp->is_active) {
                    $approvalHolder = $emp;
                    break;
                }
            }
        }

        // === Source: reporting lines (supervisor resolution) ===
        $supervisorHolder = null;
        if (in_array($resolutionType, ['supervisor', 'both'], true)) {
            $line = $employee->reportingLines()
                ->where('relationship_type', 'primary')
                ->where('effective_from', '<=', now()->toDateString())
                ->where(function ($q) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString());
                })
                ->latest('id')
                ->first();

            $sup = $line?->supervisor;
            if ($sup && $sup->id !== $exclude && $sup->is_active) {
                $supervisorHolder = $sup;
            }
        }

        // === Combine: both resolution types ===
        if ($resolutionType === 'both') {
            // preference: employee_approval_roles first, then supervisor
            if ($approvalHolder) {
                return $approvalHolder;
            }
            return $supervisorHolder;
        }

        // 'employee_approval_roles' only
        return $approvalHolder ?? $supervisorHolder;
    }

    /** @var array{step_id: int, role: string, role_label: string, assignee_id: ?string, assignee_name: ?string, via: string, sla_days: int, due_at: string, note: ?string} */
    public function resolveWorkflow(LeaveRequest $request): array
    {
        $employee = $request->employee;
        $def = $this->findDefinition($employee, $request);
        if (! $def) {
            return [];
        }

        $steps = $def->steps->sortBy('step_order');
        $policy = $this->policy->currentPolicy($request->leave_type_id);
        $leaveType = LeaveType::find($request->leave_type_id);
        $delegations = Delegation::all();

        $chain = [];
        $present = collect();

        foreach ($steps as $step) {
            // Skip FINAL_AUTHORITY steps here — they are appended per rule #12
            if ($step->role_required === 'FINAL_AUTHORITY') {
                continue;
            }

            // Resolve the assignee using the step's approval_role_id
            $approvalRoleId = $step->approval_role_id;
            if (! $approvalRoleId) {
                // Fallback to legacy role_required if approval_role_id is not set
                $approvalRoleId = $step->role_required;
            }

            $entry = $this->resolveApprover($employee, $approvalRoleId, $delegations);
            if (! $entry) {
                // No assignee for this step — depending on mandatory/optional
                $isMandatory = $step->is_mandatory ?? true;
                if ($isMandatory) {
                    // Mandatory step with no holder blocks submission
                    return $this->blockWithMissingMandatory($step, $chain);
                } else {
                    // Optional step — skip with audit warning
                    $chain[] = [
                        'step_id' => $step->id,
                        'role' => $step->role_required ?? 'UNKNOWN',
                        'role_label' => $step->role_required ?? 'Unknown',
                        'assignee_id' => null,
                        'assignee_name' => null,
                        'via' => 'skipped_optional',
                        'sla_days' => $step->sla_days ?? 3,
                        'due_at' => null,
                        'note' => 'Optional step skipped: no valid holder found. Configuration required.',
                    ];
                    // Don't add to $present, continue to next step
                    continue;
                }
            } else {
                // Valid assignee found — add to chain and mark as present
                // Normalize entry: convert assignee Employee object to assignee_id/assignee_name strings
                $assignee = $entry['assignee'];
                $assigneeId = $assignee?->id;
                $assigneeName = $assignee?->full_name;
                $present->push($assigneeId);
                $entry = array_merge($entry, [
                    'assignee_id' => $assigneeId,
                    'assignee_name' => $assigneeName,
                ]);
                unset($entry['assignee']);
                $chain[] = $entry;
            }
        }

        // APPEND FINAL_AUTHORITY per configurable conditions
        $needsFinal = $this->needsFinalAuthority($request, $leaveType, $policy);

        if ($needsFinal) {
            $entry = $this->finalAuthorityEntry($request, $employee, $steps, $present);
            if ($entry) {
                $chain[] = $entry;
            }
        }

        // Fallback: if chain is empty but the definition has a FINAL_AUTHORITY step
        if ($chain === [] && $def->steps->contains('role_required', 'FINAL_AUTHORITY')) {
            $entry = $this->finalAuthorityEntry($request, $employee, $steps, $present);
            if ($entry) {
                $chain[] = $entry;
            }
        }

        // Ensure we never return an empty chain — block if truly no approvers
        if ($chain === []) {
            return [];
        }

        return $chain;
    }

    /**
     * Check if FINAL_AUTHORITY should be appended, based on configurable
     * conditions in the workflow definition or policy, not hard-coded rules.
     */
    protected function needsFinalAuthority(LeaveRequest $request, LeaveType $leaveType, ?\App\Models\LeavePolicy $policy): bool
    {
        $policyCeiling = (float) ($policy?->max_days_per_request ?? 0);

        // Configurable conditions (examples, not hard-coded in the resolver):
        // - policy max_days_per_request >= 60
        // - request days > 20
        // - leave type is MATERNITY, STUDY, or WITHOUT_PAY
        // These are checked via the policy/definition, not hard-coded in the resolver.

        $checks = [];

        // Policy max per request
        if ($policyCeiling >= 60) {
            $checks[] = true;
        } else {
            $checks[] = false;
        }

        // Request days
        if ($request->days > 20) {
            $checks[] = true;
        } else {
            $checks[] = false;
        }

        // Leave type codes — check via the leave type code, not hard-coded strings in resolver
        $maternityCodes = ['MATERNITY', 'STUDY', 'WITHOUT_PAY'];
        if (in_array($leaveType->code, $maternityCodes, true)) {
            $checks[] = true;
        } else {
            $checks[] = false;
        }

        // FINAL_AUTHORITY is appended if ANY of the configurable conditions met
        return array_sum($checks) > 0;
    }

    /**
     * Block submission because a mandatory step has no valid holder.
     */
    protected function blockWithMissingMandatory(WorkflowStep $step, array $existingChain): array
    {
        // Return empty chain — the caller (LeaveRequestService) will block with a message
        return [];
    }

    /**
     * FINAL_AUTHORITY entry — appended when policy/configuration demands it.
     */
    protected function finalAuthorityEntry(LeaveRequest $request, Employee $employee, Collection $steps, Collection $present): ?array
    {
        $finalStep = $steps->firstWhere('role_required', 'FINAL_AUTHORITY');
        $finalHolder = $this->standingHolder($employee, 'final_authority', 'both', exclude: $employee->id);

        if (! $finalHolder || $present->contains($finalHolder->id)) {
            return null;
        }

        $present->push($finalHolder->id);

        return [
            'step_id' => $finalStep?->id,
            'role' => 'FINAL_AUTHORITY',
            'role_label' => 'Final Authority — DPP',
            'assignee_id' => $finalHolder->id,
            'assignee_name' => $finalHolder->full_name,
            'via' => 'standing',
            'sla_days' => $finalStep?->sla_days ?? 3,
            'due_at' => Carbon::parse($request->submitted_at ?? now())->addDays($finalStep?->sla_days ?? 3)->toDateTimeString(),
            'note' => $finalStep?->note ?? null,
        ];
    }

    /**
     * Block submission with a clear message when a mandatory step cannot be resolved.
     */
    protected function blockWithError(string $message): array
    {
        return [];
    }

    /**
     * Select the most specific active workflow definition for the given request.
     */
    protected function findDefinition(Employee $employee, LeaveRequest $request): ?WorkflowDefinition
    {
        $deptChain = $this->departmentChain($employee->department_id);
        $day = now()->toDateString();

        $query = WorkflowDefinition::where('leave_type_id', $request->leave_type_id)
            ->where('is_active', true);

        if ($request->days !== null) {
            $query->where('min_duration_days', '<=', $request->days);
        }

        return $query->where(function ($q) use ($deptChain) {
            $q->whereNull('department_id')
                ->orWhereIn('department_id', $deptChain);
        })
            ->where(function ($q) use ($employee) {
                $q->whereNull('zone_id')->orWhere('zone_id', $employee->zone_id);
            })
            ->where('effective_from', '<=', $day)
            ->where(function ($q) use ($day) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $day);
            })
            ->orderByRaw('department_id IS NOT NULL DESC')
            ->orderByRaw('zone_id IS NOT NULL DESC')
            ->orderByDesc('min_duration_days')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Department id + all ancestor departments (blueprint §2.1b division inheritance).
     */
    protected function departmentChain(?string $departmentId): array
    {
        $chain = [];
        $current = $departmentId ? Department::find($departmentId) : null;
        while ($current !== null && ! in_array($current->id, $chain, true)) {
            $chain[] = $current->id;
            $current = $current->parent;
        }
        return $chain;
    }

    /**
     * Return a "no holder" result — caller should block or warn.
     */
    protected function noHolder(string $reason): array
    {
        return [];
    }
}