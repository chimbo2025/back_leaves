<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveRequest extends Model
{
    protected $fillable = [
        'ref', 'employee_id', 'leave_type_id', 'policy_id', 'policy_snapshot', 'required_docs_provided',
        'medical_required', 'eligibility_checked_at', 'eligibility_blockers', 'eligibility_ok', 'policy_version',
        'start_date', 'end_date', 'days', 'is_half_day', 'is_emergency_exception', 'exception_reason', 'reason',
        'status', 'current_owner_id', 'submitted_at', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'is_half_day' => 'boolean',
            'is_emergency_exception' => 'boolean',
            'policy_snapshot' => 'array',
            'required_docs_provided' => 'array',
            'eligibility_blockers' => 'array',
            'medical_required' => 'boolean',
            'eligibility_ok' => 'boolean',
            'eligibility_checked_at' => 'datetime',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'policy_id');
    }

    public function currentOwner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'current_owner_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkflowAssignment::class, 'request_id')->orderBy('id');
    }

    public function requestDates(): HasMany
    {
        return $this->hasMany(LeaveRequestDate::class, 'request_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LeaveDocument::class, 'request_id');
    }
}
