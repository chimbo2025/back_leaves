<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Positions are simple job titles (organization refactor).
 *
 * They carry no approval authority: no tiers, no final-authority flag and no
 * default roles. Approval authority comes from employee_roles/role_permissions
 * and from the dynamic approval-role catalog used by workflow steps.
 */
class Position extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'code', 'title', 'title_sw', 'department_id',
        'division_id', 'unit_id', 'zone_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'division_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'unit_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * Legacy default-role rows are no longer created or exposed. The relation
     * is kept read-only so historical rows can be inspected without breaking
     * older callers.
     */
    public function defaultRoles(): HasMany
    {
        return $this->hasMany(PositionDefaultRole::class, 'position_id');
    }
}
