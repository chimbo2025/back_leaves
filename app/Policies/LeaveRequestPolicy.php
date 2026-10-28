<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\CaseHandoverService;
use App\Services\PermissionService;

class LeaveRequestPolicy
{
    public function __construct(
        protected AccessScopeService $scope,
        protected PermissionService $permissions,
    ) {}

    /** Listing requires either org-wide reach or at least one zone/department grant. */
    public function viewAny(User $user): bool
    {
        return $this->scope->forUser($user, AccessScopeService::REQUESTS)->hasZoneScope()
            || $this->scope->canViewAllZones($user, AccessScopeService::REQUESTS);
    }

    /**
     * Visible to the requester, any assignee on the chain, and regional
     * officers whose zone/department scope covers the requester's office.
     */
    public function view(User $user, LeaveRequest $request): bool
    {
        $employeeId = $user->employee?->id;

        // The owner branch carries its own code so viewing your own requests
        // is revocable like every other capability (SELF_SERVICE grants it to
        // every active employee, so this changes nothing for ordinary staff).
        if ($employeeId !== null && $request->employee_id === $employeeId) {
            return $this->permissions->can($user, 'requests', 'view_own');
        }

        if ($employeeId !== null && $request->assignments()->where('assigned_employee_id', $employeeId)->exists()) {
            return true;
        }

        $scope = $this->scope->forUser($user, AccessScopeService::REQUESTS);

        if ($scope->canViewAllZones) {
            return true;
        }

        if (! $scope->hasZoneScope()) {
            return false;
        }

        $owner = $request->employee;

        if (! $owner) {
            return false;
        }

        return in_array($owner->zone_id, $scope->allowedZoneIds, true)
            || in_array($owner->department_id, $scope->allowedDepartmentIds, true);
    }

    /**
     * Mutation (attaching a supporting document) is limited to the requester
     * and currently assigned approvers, plus central HR administering documents
     * organisation-wide. Zone scope alone never authorises a write — a regional
     * officer must not be able to attach files to another office's request just
     * because it shares their zone.
     */
    public function update(User $user, LeaveRequest $request): bool
    {
        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return false;
        }

        if ($request->employee_id === $employeeId) {
            return true;
        }

        if ($request->assignments()
            ->where('status', 'pending')
            ->where('assigned_employee_id', $employeeId)
            ->exists()) {
            return true;
        }

        return $this->permissions->can($user, 'documents', 'create')
            && $this->permissions->can($user, 'requests', 'view_cross_zone');
    }

    /**
     * Authority to propose a handover of the requester's court cases.
     *
     * Distinct from `view` on purpose. Zone and department scope are a READ
     * predicate - they say which requests an officer can look at - and deriving
     * a write from them let any regional officer move a live court case to an
     * arbitrary colleague on a request they merely had sight of.
     *
     * Both conditions must hold, and the order matters.
     *
     * Visibility first: approval authority alone is not enough. requests.approve
     * is carried by every ZONAL_HEAD, so testing capability first let a zonal
     * head propose handovers on requests they cannot even see - the exact
     * cross-zone leak this predicate exists to close.
     *
     * Then authority to write, once the caller can legitimately read the row:
     *   - the requester, who is the officer whose cases are being reassigned;
     *   - an officer holding requests.approve, acting in the requester's stead;
     *   - central HR administering documents org-wide.
     *
     * The route additionally requires case_handovers.create for everyone
     * (including the requester), so handover access is matrix-visible. The
     * two layers divide the job: the code is the floor ("may touch handover
     * flow at all"), this predicate is the tier ("may act on someone else's
     * docket"). A zone-scoped reader who holds the code but no approver tier
     * still cannot move another officer's live case.
     *
     * The request must also be one where the requester still owns open court
     * cases, otherwise there is nothing to hand over and CaseHandoverService would
     * reject it anyway - checked here so the refusal is an authorization
     * decision rather than a validation surprise.
     */
    public function proposeHandover(User $user, LeaveRequest $request): bool
    {
        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return false;
        }

        if (! $this->view($user, $request)) {
            return false;
        }

        $isRequester = $request->employee_id === $employeeId;
        $isApprover = $this->permissions->can($user, 'requests', 'approve');
        $isCentralHr = $this->permissions->can($user, 'documents', 'create')
            && $this->permissions->can($user, 'requests', 'view_cross_zone');

        if (! $isRequester && ! $isApprover && ! $isCentralHr) {
            return false;
        }

        return app(CaseHandoverService::class)
            ->openCourtCases($request->employee)
            ->isNotEmpty();
    }

    /**
     * Decision rights remain assignment-derived and are deliberately NOT zone
     * derived: zone scope grants visibility, not the authority to approve.
     */
    public function approve(User $user, LeaveRequest $request): bool
    {
        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return false;
        }

        return $request->assignments()
            ->where('status', 'pending')
            ->where('assigned_employee_id', $employeeId)
            ->exists();
    }
}
