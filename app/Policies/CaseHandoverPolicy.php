<?php

namespace App\Policies;

use App\Models\CaseHandover;
use App\Models\User;
use App\Services\PermissionService;

class CaseHandoverPolicy
{
    public function __construct(protected PermissionService $permissions) {}

    /** Handover metadata (parties, case, notes) inherits request visibility. */
    public function view(User $user, CaseHandover $handover): bool
    {
        $request = $handover->request;

        if (! $request) {
            return false;
        }

        return app(LeaveRequestPolicy::class)->view($user, $request);
    }

    /** Only the named recipient may respond to a proposed handover. */
    public function accept(User $user, CaseHandover $handover): bool
    {
        return $this->isRecipient($user, $handover);
    }

    public function reject(User $user, CaseHandover $handover): bool
    {
        return $this->isRecipient($user, $handover);
    }

    /**
     * Capacity-review sign-off: the recipient, or an approver-tier officer
     * (supervisory escalation) who is not the recipient. The route already
     * requires case_handovers.complete of everyone, so this predicate is the
     * tier check: recipients sign off their own dockets, everyone else needs
     * approver standing. Same two-layer rule as proposeHandover.
     */
    public function complete(User $user, CaseHandover $handover): bool
    {
        if ($this->isRecipient($user, $handover)) {
            return true;
        }

        return $this->permissions->can($user, 'requests', 'approve');
    }

    protected function isRecipient(User $user, CaseHandover $handover): bool
    {
        $employeeId = $user->employee?->id;

        return $employeeId !== null && $handover->to_employee_id === $employeeId;
    }
}
