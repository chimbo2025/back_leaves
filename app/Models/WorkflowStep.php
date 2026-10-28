<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowStep extends Model
{
    protected $fillable = [
        'workflow_def_id', 'step_order', 'approval_role_id', 'role_required', 'sla_days', 'is_mandatory', 'escalation_after_sla', 'note',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'escalation_after_sla' => 'boolean',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_def_id');
    }

    /** @see approvalRole() */
    public function approvalRole(): BelongsTo
    {
        return $this->belongsTo(ApprovalRole::class, 'approval_role_id');
    }

    /** @see legacyRole() */
    public function legacyRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_required');
    }
}