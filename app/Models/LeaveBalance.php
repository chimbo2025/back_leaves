<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    protected $fillable = [
        'employee_id', 'leave_type_id', 'year', 'accrued_days',
        'carried_over_days', 'used_days', 'expiry_date',
    ];

    protected function casts(): array
    {
        return [
            'accrued_days' => 'float',
            'carried_over_days' => 'float',
            'used_days' => 'float',
            'expiry_date' => 'date:Y-m-d',
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
}
