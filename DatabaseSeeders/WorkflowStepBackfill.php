<?php

namespace DatabaseSeeders;

use App\Models\ApprovalRole;
use App\Models\WorkflowStep;
use Illuminate\Support\Str;

class WorkflowStepApprovalRoleBackfill
{
    public static function run(): void
    {
        // Map legacy role_required strings to new approval_role codes
        $map = [
            'SUPERVISOR' => 'supervisor',
            'DEPT_HEAD' => 'department_head',
            'ZONAL_HEAD' => 'zonal_head',
            'HR' => 'hr_reviewer',
            'FINAL_AUTHORITY' => 'final_authority',
        ];

        foreach ($map as $legacyCode => $newCode) {
            $role = ApprovalRole::where('code', $newCode)->first();
            if (! $role) {
                continue;
            }

            WorkflowStep::where('role_required', $legacyCode)
                ->whereNull('approval_role_id')
                ->update(['approval_role_id' => $role->id]);
        }
    }
}