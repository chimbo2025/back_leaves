<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delegation extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'delegator_employee_id', 'delegate_employee_id', 'role_id', 'approval_role_id',
        'start_date', 'end_date', 'authorized_by', 'active',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'active' => 'boolean',
    ];

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'delegator_employee_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'delegate_employee_id');
    }

    public function approvalRole(): BelongsTo
    {
        return $this->belongsTo(ApprovalRole::class, 'approval_role_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}