<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'user_id', 'payroll_no', 'full_name', 'initials', 'position_id', 'department_id',
        'zone_id', 'gender', 'email', 'hire_date', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'employee_roles', 'employee_id', 'role_id')
            ->withPivot(['department_id', 'scope_zone_id', 'source', 'effective_from', 'effective_to']);
    }

    public function reportingLines(): HasMany
    {
        return $this->hasMany(ReportingLine::class, 'employee_id');
    }

    public function supervised(): HasMany
    {
        return $this->hasMany(ReportingLine::class, 'supervisor_employee_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * Role IDs this employee holds unconditionally.
     *
     * Anything absent here is a scoped structural grant (department head of d1,
     * zonal head of z4). Only these may be written as unscoped rows; the rest are
     * owned by the single-grant endpoints.
     *
     * @return array<int, string>
     */
    public function unscopedRoleIds(): array
    {
        return $this->roles()
            ->wherePivotNull('department_id')
            ->wherePivotNull('scope_zone_id')
            ->pluck('roles.id')
            ->all();
    }
}
