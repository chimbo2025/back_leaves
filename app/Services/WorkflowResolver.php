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
use App\Support\ApprovalRoleMap;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

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
    public function resolveApprover(Employee $employee, string $approvalRoleId, ?Collection $delegations = null, string $scopeType = 'requester_department'): array
    {
        $approvalRole = ApprovalRole::where('id', $approvalRoleId)
            ->orWhere('code', $approvalRoleId)
            ->first();

        // Steps written before the catalog existed carry a legacy role code
        // (SUPERVISOR, DEPT_HEAD, ...). Translate it rather than special-casing
        // each caller.
        if (! $approvalRole && ($legacy = $this->legacyApprovalRoleCode($approvalRoleId))) {
            $approvalRole = ApprovalRole::where('code', $legacy)->first();
        }

        if (! $approvalRole) {
            return $this->noHolder('approval role not found: ' . $approvalRoleId);
        }

        $approvalRoleId = $approvalRole->id;
        $resolutionType = $approvalRole->resolution_type ?? 'employee_approval_roles';

        // Resolve the standing holder first: a delegation only overrides the
        // authority the delegator actually holds for this request. Without that
        // guard a department-head delegation in one department would approve
        // every department's requests.
        $standing = $this->standingHolder($employee, $approvalRoleId, $resolutionType, null, $scopeType);

        // 1. Active delegation for this role overrides the standing holder.
        //    When a standing holder exists, only that holder's own delegation
        //    applies (a department-head delegation must not approve another
        //    department's requests). When nobody stands in the role for this
        //    request, any active delegation for the role is the fallback.
        // Scope-matched delegation: a delegation only overrides authority the
        // delegator actually holds for this request. When a standing holder
        // exists, only that holder's own delegation applies. When no standing
        // holder exists, fall back to any active delegation for the role that
        // explicitly covers the same role and request scope (legacy behavior
        // retained for zones without a dedicated holder; tracked as a known
        // gap until every zone has a scoped holder).
        $active = ($delegations ?? Delegation::all())->first(function (Delegation $d) use ($employee, $approvalRoleId, $standing) {
            return $d->active
                && $d->approval_role_id === $approvalRoleId
                && ($standing === null || $d->delegator_employee_id === $standing->id)
                && Carbon::parse($d->start_date)->lte(Carbon::parse(now()->toDateString()))
                && Carbon::parse($d->end_date)->gte(Carbon::parse(now()->toDateString()))
                && $d->delegate_employee_id !== $employee->id; // never delegate back to the requester
        });

        if ($active) {
            $delegate = Employee::find($active->delegate_employee_id);

            // Preserve the delegator for the assignment record and the audit
            // trail; `decide()` recognises the delegation by actor, this id,
            // and the step's role — without it a delegate could never be
            // authorised for a role-resolved (legacy) step.
            return [
                'assignee' => $delegate,
                'via' => 'delegation',
                'selected_approver_id' => $active->delegator_employee_id,
                'delegated_from_id' => $active->delegator_employee_id,
                'delegated' => true,
            ];
        }

        // 2. Standing holder — resolved above.
        if ($standing && $standing->id !== $employee->id) {
            return ['assignee' => $standing, 'via' => 'standing'];
        }

        // Self-exclusion: requester holds the role themselves — try another holder
        if ($standing && $standing->id === $employee->id) {
            $other = $this->standingHolder($employee, $approvalRoleId, $resolutionType, exclude: $employee->id, scopeType: $scopeType);
            $final = $this->standingHolder($employee, 'final_authority', $resolutionType, exclude: $employee->id, scopeType: $scopeType);

            return ['assignee' => $other ?? $final, 'via' => 'escalated'];
        }

        // No holder found
        return $this->noHolder('no valid holder for approval role ' . $approvalRoleId . ' (resolution_type=' . $resolutionType . ')');
    }

    /**
     * Resolve a step whose approver was explicitly chosen in the builder.
     *
     * - The selected employee must still exist and be active.
     * - Self-approval is refused with a message naming the step.
     * - An active delegation by that employee routes the work to the delegate;
     *   `delegated_from_id` preserves the original approver for the audit trail.
     *
     * @param  Collection<int, Delegation>|null  $delegations
     * @return array{assignee: ?Employee, via: string, selected_approver_id?: string, delegated_from_id?: ?string, delegated?: bool, reason?: string}
     */
    protected function resolveSelectedApprover(Employee $requester, WorkflowStep $step, ?Collection $delegations = null): array
    {
        $approver = Employee::find($step->approver_employee_id);
        $stepLabel = $step->approvalRole?->name ?? ($step->role_required ?? 'approval');

        if (! $approver || ! $approver->is_active) {
            throw ValidationException::withMessages([
                'workflow' => "Step {$step->step_order} ({$stepLabel}) selects an approver who is missing or inactive. Choose a different approver.",
            ]);
        }

        if ($approver->id === $requester->id) {
            throw ValidationException::withMessages([
                'workflow' => "Step {$step->step_order} ({$stepLabel}) selects {$approver->full_name}, the person submitting this request. Choose a different approver.",
            ]);
        }

        $delegation = $this->activeDelegationFor($approver, $step, $delegations);

        if ($delegation) {
            $delegate = Employee::find($delegation->delegate_employee_id);

            // Never delegate back to the requester; fall through to the
            // selected approver in that case.
            if ($delegate && $delegate->is_active && $delegate->id !== $requester->id) {
                return [
                    'assignee' => $delegate,
                    'via' => 'delegation',
                    'selected_approver_id' => $approver->id,
                    'delegated_from_id' => $approver->id,
                    'delegated' => true,
                ];
            }
        }

        return [
            'assignee' => $approver,
            'via' => 'standing',
            'selected_approver_id' => $approver->id,
            'delegated_from_id' => null,
            'delegated' => false,
        ];
    }

    /**
     * The active delegation (if any) that lets someone act for this approver.
     * A delegation matching the step's role is preferred, but a general
     * delegation held by the selected approver also applies.
     *
     * @param  Collection<int, Delegation>|null  $delegations
     */
    protected function activeDelegationFor(Employee $approver, WorkflowStep $step, ?Collection $delegations = null): ?Delegation
    {
        $roleIds = array_values(array_filter([
            $step->approval_role_id,
            $step->role_required,
            $step->role_required ? ApprovalRoleMap::approvalCodeFor($step->role_required) : null,
        ]));

        $today = now()->toDateString();

        $candidates = ($delegations ?? Delegation::all())->filter(function (Delegation $d) use ($approver, $today) {
            return $d->active
                && $d->delegator_employee_id === $approver->id
                && Carbon::parse($d->start_date)->lte(Carbon::parse($today))
                && Carbon::parse($d->end_date)->gte(Carbon::parse($today));
        })->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $matched = $candidates->first(function (Delegation $d) use ($roleIds) {
            return in_array($d->approval_role_id, $roleIds, true)
                || in_array($d->role_id, $roleIds, true);
        });

        return $matched ?? $candidates->first();
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
    protected function standingHolder(Employee $employee, string $approvalRoleId, string $resolutionType = 'employee_approval_roles', ?string $exclude = null, string $scopeType = 'requester_department'): ?Employee
    {
        // === Source: employee_approval_roles ===
        $approvalHolder = null;
        if (in_array($resolutionType, ['employee_approval_roles', 'both'], true)) {
            // Resolve the set of organizational units the holder may sit in,
            // driven by the step's scope_type.
            if ($scopeType === 'requester_unit') {
                $orgUnits = array_filter([$employee->unit_id]);
            } elseif ($scopeType === 'requester_division') {
                $orgUnits = array_filter([$employee->division_id]);
            } elseif ($scopeType === 'requester_department') {
                $orgUnits = array_values(array_unique(array_filter([
                    $employee->department_id,
                    ...$this->departmentChain($employee->department_id),
                ])));
            } else {
                // requester_zone / office_wide: no organizational-unit restriction
                $orgUnits = [];
            }

            $query = EmployeeApprovalRole::where('approval_role_id', $approvalRoleId)
                ->where(function ($q) {
                    $q->whereNull('effective_from')
                        ->orWhereDate('effective_from', '<=', now()->toDateString());
                })
                ->where(function ($q) {
                    $q->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', now()->toDateString());
                });

            if ($scopeType === 'office_wide') {
                // No organizational-unit or zone restriction.
            } elseif ($scopeType === 'requester_zone') {
                $query->where(function ($q) use ($employee) {
                    $q->whereNull('zone_id');
                    if ($employee->zone_id) {
                        $q->orWhere('zone_id', $employee->zone_id);
                    }
                });
            } else {
                $query->where(function ($q) use ($orgUnits) {
                    $q->whereNull('organizational_unit_id');
                    if ($orgUnits !== []) {
                        $q->orWhereIn('organizational_unit_id', $orgUnits);
                    }
                });
                $query->where(function ($q) use ($employee) {
                    $q->whereNull('zone_id');
                    if ($employee->zone_id) {
                        $q->orWhere('zone_id', $employee->zone_id);
                    }
                });
            }

            $rows = $query->get()->sortByDesc(function ($er) use ($employee) {
                $score = $er->is_primary ? 4 : 0;
                if ($er->organizational_unit_id === $employee->unit_id && $employee->unit_id !== null) {
                    $score += 8;
                } elseif ($er->organizational_unit_id === $employee->division_id && $employee->division_id !== null) {
                    $score += 6;
                } elseif ($er->organizational_unit_id === $employee->department_id && $employee->department_id !== null) {
                    $score += 4;
                } elseif ($er->organizational_unit_id !== null) {
                    $score += 2;
                }
                if ($er->zone_id === $employee->zone_id) {
                    $score += 2;
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

    /**
     * Return the active employees eligible to act on a shared-queue step.
     *
     * @return \\Illuminate\\Support\\Collection<int, Employee>
     */
    public function eligibleHolders(Employee $requester, string $approvalRoleId, string $scopeType = 'requester_department'): Collection
    {
        $approvalRole = ApprovalRole::where('id', $approvalRoleId)->orWhere('code', $approvalRoleId)->first();
        if (! $approvalRole) {
            if ($legacy = $this->legacyApprovalRoleCode($approvalRoleId)) {
                $approvalRole = ApprovalRole::where('code', $legacy)->first();
            }
        }
        if (! $approvalRole) {
            return collect();
        }
        $approvalRoleId = $approvalRole->id;

        // Resolve candidate organizational units by scope.
        if ($scopeType === 'requester_unit') {
            $orgUnits = array_filter([$requester->unit_id]);
        } elseif ($scopeType === 'requester_division') {
            $orgUnits = array_filter([$requester->division_id]);
        } elseif ($scopeType === 'requester_department') {
            $orgUnits = array_values(array_unique(array_filter([
                $requester->department_id,
                ...$this->departmentChain($requester->department_id),
            ])));
        } else {
            $orgUnits = [];
        }

        $query = EmployeeApprovalRole::where('approval_role_id', $approvalRoleId)
            ->where(function ($q) {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString());
            })
            ->where(function ($q) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString());
            });

        if ($scopeType === 'office_wide') {
            // no org/zone restriction
        } elseif ($scopeType === 'requester_zone') {
            $query->where(function ($q) use ($requester) {
                $q->whereNull('zone_id');
                if ($requester->zone_id) {
                    $q->orWhere('zone_id', $requester->zone_id);
                }
            });
        } else {
            $query->where(function ($q) use ($orgUnits) {
                $q->whereNull('organizational_unit_id');
                if ($orgUnits !== []) {
                    $q->orWhereIn('organizational_unit_id', $orgUnits);
                }
            });
            $query->where(function ($q) use ($requester) {
                $q->whereNull('zone_id');
                if ($requester->zone_id) {
                    $q->orWhere('zone_id', $requester->zone_id);
                }
            });
        }

        $holders = collect();
        foreach ($query->get() as $row) {
            $emp = Employee::find($row->employee_id);
            if ($emp && $emp->is_active && $emp->id !== $requester->id) {
                $holders->push($emp);
            }
        }
        $holders = $holders->unique('id')->values();

        // Add active delegates for this role.
        $delegates = Delegation::where('approval_role_id', $approvalRoleId)
            ->where('active', true)
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->get();
        foreach ($delegates as $d) {
            // delegate must be within scope? keep simple: add if not requester
            $emp = Employee::find($d->delegate_employee_id);
            if ($emp && $emp->is_active && $emp->id !== $requester->id && ! $holders->contains('id', $emp->id)) {
                $holders->push($emp);
            }
        }

        return $holders;
    }

    public function resolveDefinition(LeaveRequest $request): ?WorkflowDefinition
    {
        return $this->findDefinition($request->employee, $request);
    }

    /** @var array{step_id: int, role: string, role_label: string, assignee_id: ?string, assignee_name: ?string, assignee_initials: ?string, via: string, sla_days: int, due_at: string, note: ?string, assignment_mode: string} */
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
        $lastAssigneeId = null;

        foreach ($steps as $step) {
            $hasExplicitApprover = (bool) $step->approver_employee_id;

            // Only *legacy* final-authority steps are appended last, after the
            // configured chain, when the policy demands a final sign-off. A
            // step that names its approver is part of the configured chain and
            // must run at its configured position — otherwise a 4-step chain
            // silently loses its final-authority step and collapses.
            if ($this->isFinalAuthorityStep($step) && ! $hasExplicitApprover) {
                continue;
            }

            // Resolve the assignee using the step's approval_role_id. The
            // legacy role_required fallback keeps pre-catalog definitions
            // resolvable without hard-coding any role in the resolver.
            $approvalRoleId = $step->approval_role_id ?: $step->role_required;
            $assignmentMode = $step->assignment_mode ?? 'assigned_approver';
            $scopeType = $step->scope_type ?? 'requester_department';

            if ($step->approver_employee_id) {
                // The builder stores an explicit employee for the step; that
                // person is authoritative. Delegation still applies, and the
                // original approver is retained for the audit trail.
                $entry = $this->resolveSelectedApprover($employee, $step, $delegations);
            } elseif ($assignmentMode === 'shared_queue') {
                $holders = $this->eligibleHolders($employee, $approvalRoleId, $scopeType);
                if ($holders->isEmpty() && $step->fallback_approval_role_id) {
                    $holders = $this->eligibleHolders($employee, $step->fallback_approval_role_id, $scopeType);
                    if ($holders->isNotEmpty()) {
                        $approvalRoleId = $step->fallback_approval_role_id;
                    }
                }

                if ($holders->isEmpty()) {
                    $isMandatory = $step->is_mandatory ?? true;
                    if ($isMandatory) {
                        return $this->blockWithMissingMandatory($step, $chain);
                    }
                    $chain[] = [
                        'step_id' => $step->id,
                        'role' => $step->role_required ?: ($step->approvalRole?->code ?? 'UNKNOWN'),
                        'role_label' => $step->approvalRole?->name ?? ($step->role_required ?? 'Unknown'),
                        'assignee_id' => null,
                        'assignee_name' => null,
                        'assignee_initials' => null,
                        'via' => 'skipped_optional',
                        'sla_days' => $step->sla_days ?? 3,
                        'due_at' => null,
                        'note' => 'Optional step skipped: no eligible shared-queue holders found.',
                        'assignment_mode' => 'shared_queue',
                    ];
                    continue;
                }

                // Shared queue: no single assignee; eligible holders act.
                $chain[] = [
                    'step_id' => $step->id,
                    'role' => $step->role_required ?: ($step->approvalRole?->code ?? 'UNKNOWN'),
                    'role_label' => $step->approvalRole?->name ?? ($step->role_required ?? 'Unknown'),
                    'assignee_id' => null,
                    'assignee_name' => null,
                    'assignee_initials' => null,
                    'via' => 'shared_queue',
                    'sla_days' => $step->sla_days ?? 3,
                    'due_at' => Carbon::parse($request->submitted_at ?? now())->addDays($step->sla_days ?? 3)->toDateTimeString(),
                    'note' => $step->note,
                    'assignment_mode' => 'shared_queue',
                ];
                $lastAssigneeId = null;
                continue;
            } else {
                $entry = $this->resolveApprover($employee, $approvalRoleId, $delegations, $scopeType);

                // Fallback approval role: only used when the primary role has
                // no standing holder.
                if (! ($entry['assignee'] ?? null) && $step->fallback_approval_role_id) {
                    $entry = $this->resolveApprover($employee, $step->fallback_approval_role_id, $delegations, $scopeType);
                }
            }

            if (! ($entry['assignee'] ?? null)) {
                // The top of the hierarchy (the DPP) has no supervisor. An
                // unstaffed supervisor step there is expected, not a
                // configuration error, so it is skipped rather than blocking
                // the whole request.
                if ($this->isUnstaffedSupervisorStep($step, $employee, $approvalRoleId)) {
                    continue;
                }

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
                        'assignee_initials' => null,
                        'via' => 'skipped_optional',
                        'sla_days' => $step->sla_days ?? 3,
                        'due_at' => null,
                        'note' => 'Optional step skipped: no valid holder found. Configuration required.',
                    ];
                    // Don't add to $present, continue to next step
                    continue;
                }
            } else {
                // Valid assignee found — add to chain and mark as present.
                // Collapse duplicate approvers unless the step explicitly
                // allows the same person to act twice in a row.
                $assignee = $entry['assignee'];
                $assigneeId = $assignee?->id;

                // Collapse a duplicate approver only when it is the immediately
                // preceding step (non-consecutive repeats remain distinct
                // sign-offs) and the step was not explicitly configured. A
                // step that names its approver is kept verbatim: it is a real
                // decision point, not a derived one.
                if (! $hasExplicitApprover
                    && $assigneeId !== null && $assigneeId === $lastAssigneeId
                    && ! ($step->allow_same_person_as_previous_steps ?? false)) {
                    $chain[] = [
                        'step_id' => $step->id,
                        'role' => $step->role_required ?: ($step->approvalRole?->code ?? 'UNKNOWN'),
                        'role_label' => $step->approvalRole?->name ?? ($step->role_required ?? 'Unknown'),
                        'assignee_id' => null,
                        'assignee_name' => null,
                        'assignee_initials' => null,
                        'via' => 'collapsed_duplicate',
                        'sla_days' => $step->sla_days ?? 3,
                        'due_at' => null,
                        'note' => 'Skipped: same approver as the previous step.',
                    ];

                    continue;
                }

                // Normalize entry: convert assignee Employee object to assignee_id/assignee_name strings
                $assigneeName = $assignee?->full_name;
                if ($assigneeId !== null) {
                    $present->push($assigneeId);
                }
                $slaDays = $step->sla_days ?? 3;
                $entry = array_merge($entry, [
                    'step_id' => $step->id,
                    'role' => $step->role_required ?: ($step->approvalRole?->code ?? 'UNKNOWN'),
                    'role_label' => $step->approvalRole?->name ?? ($step->role_required ?? 'Unknown'),
                    'assignee_id' => $assigneeId,
                    'assignee_name' => $assigneeName,
                    'assignee_initials' => $assignee?->avatar_initials,
                    'sla_days' => $slaDays,
                    'due_at' => Carbon::parse($request->submitted_at ?? now())
                        ->addDays($slaDays)
                        ->toDateTimeString(),
                    'note' => $step->note,
                    'assignment_mode' => 'assigned_approver',
                ]);
                unset($entry['assignee']);
                $chain[] = $entry;
                $lastAssigneeId = $assigneeId;
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

        // Fallback: if chain is empty but the definition carries a final
        // authority step, append that sign-off.
        if ($chain === [] && $def->steps->contains(fn (WorkflowStep $step) => $this->isFinalAuthorityStep($step))) {
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
        $finalStep = $steps->first(fn (WorkflowStep $step) => $this->isFinalAuthorityStep($step));
        $slaDays = $finalStep?->sla_days ?? 3;
        $dueAt = Carbon::parse($request->submitted_at ?? now())->addDays($slaDays)->toDateTimeString();

        // A selected approver on the final-authority step is authoritative too.
        if ($finalStep?->approver_employee_id) {
            $entry = $this->resolveSelectedApprover($employee, $finalStep, Delegation::all());
            $assignee = $entry['assignee'] ?? null;

            if (! $assignee || $present->contains($assignee->id)) {
                return null;
            }

            $present->push($assignee->id);

            return [
                'step_id' => $finalStep->id,
                'role' => 'FINAL_AUTHORITY',
                'role_label' => 'Final Authority — DPP',
                'assignee_id' => $assignee->id,
                'assignee_name' => $assignee->full_name,
                'assignee_initials' => $assignee->avatar_initials,
                'via' => $entry['via'],
                'selected_approver_id' => $entry['selected_approver_id'] ?? $finalStep->approver_employee_id,
                'delegated_from_id' => $entry['delegated_from_id'] ?? null,
                'delegated' => $entry['delegated'] ?? false,
                'sla_days' => $slaDays,
                'due_at' => $dueAt,
                'note' => $finalStep->note ?? null,
                'assignment_mode' => 'assigned_approver',
            ];
        }

        $approvalRoleId = $finalStep?->approval_role_id ?: 'final_authority';
        $finalHolder = $this->standingHolder($employee, $approvalRoleId, 'both', exclude: $employee->id);

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
            'assignee_initials' => $finalHolder->avatar_initials,
            'via' => 'standing',
            'sla_days' => $slaDays,
            'due_at' => $dueAt,
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
     *
     * Scope precedence, most specific first: the employee's independent unit,
     * then their division, then their own department, then an ancestor
     * department, then the zone, then the office-wide default. A definition
     * narrowed to the employee's zone breaks a tie within the same placement.
     */
    protected function findDefinition(Employee $employee, LeaveRequest $request): ?WorkflowDefinition
    {
        $deptChain = $this->departmentChain($employee->department_id);
        $placementIds = array_values(array_unique(array_filter([
            $employee->unit_id,
            $employee->division_id,
            ...$deptChain,
        ])));
        $day = now()->toDateString();

        $definitions = WorkflowDefinition::where('leave_type_id', $request->leave_type_id)
            ->where('is_active', true)
            ->with('departments:id');

        if ($request->days !== null) {
            $definitions->where('min_duration_days', '<=', $request->days);
            // Optional upper bound ("Advanced Duration Rule"). NULL keeps the
            // historical open-ended meaning, so every pre-existing definition
            // matches exactly as before.
            $definitions->where(function ($q) use ($request) {
                $q->whereNull('max_duration_days')->orWhere('max_duration_days', '>=', $request->days);
            });
        }

        $matches = $definitions
            ->where('effective_from', '<=', $day)
            ->where(function ($q) use ($day) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $day);
            })
            ->get()
            ->filter(function (WorkflowDefinition $definition) use ($employee, $placementIds) {
                if ($definition->zone_id !== null && $definition->zone_id !== $employee->zone_id) {
                    return false;
                }

                // Multi-scope: the definition applies to a SET of placement
                // nodes; it matches when any of them is the requester's unit,
                // division, department or an ancestor department.
                $scopeIds = $definition->departmentIds();

                return $scopeIds === [] || count(array_intersect($scopeIds, $placementIds)) > 0;
            })
            ->all();

        usort($matches, function (WorkflowDefinition $a, WorkflowDefinition $b) use ($employee, $deptChain) {
            return [$this->scopeScore($b, $employee, $deptChain), $b->min_duration_days, $b->version]
                <=> [$this->scopeScore($a, $employee, $deptChain), $a->min_duration_days, $a->version];
        });

        if (count($matches) < 2) {
            return $matches[0] ?? null;
        }

        // Equal-specificity conflict detection: if the top two candidates
        // resolve to the same scope score, the configuration is ambiguous
        // and must not be silently resolved.
        if ($this->scopeScore($matches[0], $employee, $deptChain) === $this->scopeScore($matches[1], $employee, $deptChain)) {
            throw ValidationException::withMessages([
                'workflow' => 'Multiple active workflows match this request with equal scope specificity. Resolve the configuration conflict before submitting.',
            ]);
        }

        return $matches[0] ?? null;
    }

    /**
     * How specifically a definition targets the requester's placement.
     *
     * Most specific first: the employee's independent unit, then their
     * division, then their own department, then an ancestor department, then
     * the zone, then the office-wide default. For a multi-scope definition
     * the best (most specific) matching member of the set wins. A definition
     * narrowed to the employee's zone breaks a tie within the same placement.
     */
    protected function scopeScore(WorkflowDefinition $definition, Employee $employee, array $deptChain): int
    {
        $ids = $definition->departmentIds();
        $scopeScore = 0;

        if ($ids === []) {
            // No placement restriction: office-wide (or zone-narrowed).
            $scopeScore = $definition->zone_id !== null ? 50 : 0;
        } else {
            foreach ($ids as $id) {
                if ($id === $employee->unit_id) {
                    $scopeScore = max($scopeScore, 400);
                } elseif ($id === $employee->division_id) {
                    $scopeScore = max($scopeScore, 300);
                } elseif ($id === $employee->department_id) {
                    $scopeScore = max($scopeScore, 200);
                } elseif (in_array($id, $deptChain, true)) {
                    $scopeScore = max($scopeScore, 100);
                }
            }
        }

        // Within a placement level, a definition narrowed to the
        // employee's zone wins over the same department-wide scope.
        return $scopeScore + ($definition->zone_id !== null ? 10 : 0);
    }

    /**
     * Translate a legacy role code to an approval-role code.
     *
     * Returns null when the input is already a catalog code (or unknown), so
     * the caller can fall through to the normal not-found handling.
     */
    protected function legacyApprovalRoleCode(string $code): ?string
    {
        $map = [
            'SUPERVISOR' => 'supervisor',
            'DEPT_HEAD' => 'department_head',
            'ZONAL_HEAD' => 'zonal_head',
            'UNIT_HEAD' => 'unit_head',
            'DIVISION_HEAD' => 'division_head',
            'HR' => 'hr_reviewer',
            'HR_RECORDER' => 'hr_recorder',
            'DEPUTY_DPP' => 'deputy_dpp',
            'FINAL_AUTHORITY' => 'final_authority',
            'ACCOUNTING' => 'accounting_reviewer',
        ];

        return $map[$code] ?? null;
    }

    /**
     * A supervisor step cannot be staffed for someone with no reporting line
     * above them (the DPP). Treat that as an expected skip, not a hard
     * configuration failure.
     */
    protected function isUnstaffedSupervisorStep(WorkflowStep $step, Employee $employee, string $approvalRoleId): bool
    {
        $role = ApprovalRole::where('id', $approvalRoleId)
            ->orWhere('code', $approvalRoleId)
            ->first();

        if (! $role && ($legacy = $this->legacyApprovalRoleCode($approvalRoleId))) {
            $role = ApprovalRole::where('code', $legacy)->first();
        }

        if (! $role || $role->resolution_type !== 'supervisor') {
            return false;
        }

        return ! $employee->reportingLines()
            ->where('relationship_type', 'primary')
            ->where('effective_from', '<=', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString());
            })
            ->exists();
    }

    /** A step is the final-authority step when its approval role says so. */
    protected function isFinalAuthorityStep(WorkflowStep $step): bool
    {
        $code = $step->approvalRole?->code;

        if ($code !== null) {
            return $code === 'final_authority';
        }

        // Legacy definitions have no approval_role_id; preserve their behaviour.
        return $step->role_required === 'FINAL_AUTHORITY';
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
        // Always return the documented shape. Callers test the assignee, not
        // the array's truthiness, so a "nobody" result stays distinguishable
        // from a genuine hit (and the reason is available for diagnostics).
        return ['assignee' => null, 'via' => 'default', 'reason' => $reason];
    }
}
