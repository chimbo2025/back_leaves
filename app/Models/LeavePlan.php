<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeavePlan extends Model
{
    protected $fillable = [
        'employee_id', 'leave_type_id', 'leave_policy_id', 'cycle_start', 'cycle_end',
        'status', 'selected_option', 'confirmed_start_date', 'confirmed_end_date',
        'confirmed_by', 'confirmed_at', 'leave_request_id',
    ];

    protected function casts(): array
    {
        return [
            'cycle_start' => 'date:Y-m-d',
            'cycle_end' => 'date:Y-m-d',
            'confirmed_start_date' => 'date:Y-m-d',
            'confirmed_end_date' => 'date:Y-m-d',
            'confirmed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'confirmed_by');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(LeavePlanOption::class, 'leave_plan_id');
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }
}
