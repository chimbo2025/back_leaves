<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalRole extends Model
{
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'code', 'name', 'name_sw', 'description',
        'is_active', 'is_system_protected', 'resolution_type',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system_protected' => 'boolean',
        'resolution_type' => 'string',
    ];

    /**
     * Resolution method for this approval role.
     *  'employee_approval_roles' — from employee_approval_roles table
     *  'supervisor'              — from reporting lines
     *  'both'                    — try both, supervisor after approval assignments
     */
    public function resolutionTypeLabel(): string
    {
        return match ($this->resolution_type) {
            'supervisor' => 'Reporting line (supervisor)',
            'employee_approval_roles' => 'Employee approval assignments',
            'both' => 'Both (supervisor + approval assignments)',
            default => $this->resolution_type,
        };
    }

    /**
     * Workflow steps that use this approval role.
     */
    public function workflowSteps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class, 'approval_role_id');
    }

    /**
     * Employee approval assignments for this role.
     */
    public function employeeApprovalRoles(): HasMany
    {
        return $this->hasMany(EmployeeApprovalRole::class, 'approval_role_id');
    }

    /**
     * Active delegations for this role.
     */
    public function delegations(): HasMany
    {
        return $this->hasMany(Delegation::class, 'approval_role_id');
    }
}