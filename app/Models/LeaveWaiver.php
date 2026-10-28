<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveWaiver extends Model
{
    protected $fillable = [
        'employee_id', 'leave_type_id', 'leave_policy_id', 'leave_cycle_start', 'leave_cycle_end',
        'requested_action', 'reason', 'requested_by', 'status', 'reviewed_by', 'reviewed_at',
        'decision_comment', 'effect_json', 'policy_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'leave_cycle_start' => 'date:Y-m-d',
            'leave_cycle_end' => 'date:Y-m-d',
            'reviewed_at' => 'datetime',
            'effect_json' => 'array',
            'policy_snapshot' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }
}
