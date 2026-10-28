<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeApprovalRole extends Model
{
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'employee_id', 'approval_role_id',
        'organizational_unit_id', 'zone_id', 'is_primary',
        'effective_from', 'effective_to', 'source',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approvalRole(): BelongsTo
    {
        return $this->belongsTo(ApprovalRole::class, 'approval_role_id');
    }
}