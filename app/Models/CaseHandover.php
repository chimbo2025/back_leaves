<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseHandover extends Model
{
    protected $fillable = [
        'request_id', 'case_id', 'from_employee_id', 'to_employee_id', 'status',
        'proposed_at', 'responded_at', 'reviewed_by', 'reviewed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'proposed_at' => 'datetime',
            'responded_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(ProsecutionCase::class, 'case_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'request_id');
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by');
    }
}
