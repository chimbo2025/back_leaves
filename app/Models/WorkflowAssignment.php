<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowAssignment extends Model
{
    protected $fillable = [
        'request_id', 'step_id', 'assigned_employee_id', 'delegated_from_employee_id', 'assigned_role_id',
        'status', 'due_at', 'decided_at',
    ];

    /**
     * Display helpers shared by the request list and the compact resource.
     * Additive: existing consumers keep reading `assigned_role_id`; the UI
     * reads `role_label` so catalog roles render their configured name
     * ("HR Reviewer") instead of the legacy permission code ("HR").
     *
     * @var list<string>
     */
    protected $appends = ['role_label', 'via'];

    public function getRoleLabelAttribute(): string
    {
        return $this->step?->approvalRole?->name ?? $this->assigned_role_id;
    }

    public function getViaAttribute(): string
    {
        return $this->delegated_from_employee_id !== null ? 'delegation' : 'standing';
    }

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'request_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'step_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }

    /**
     * Original selected approver when an active delegation routed this step to
     * the assignee. Null for normal assignments.
     */
    public function delegatedFrom(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'delegated_from_employee_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'assigned_role_id');
    }

    public function approvalActions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class, 'assignment_id');
    }
}
