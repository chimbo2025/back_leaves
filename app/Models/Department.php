<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Department extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'name', 'code', 'name_sw', 'zone_id', 'parent_department_id',
        'unit_type', 'is_active', 'sort_order',
        'reports_directly_to_leadership', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'reports_directly_to_leadership' => 'boolean',
            'sort_order' => 'integer',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_department_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_department_id');
    }

    /** Divisions that sit under this department (organizational placement). */
    public function divisions(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_department_id')
            ->where('unit_type', 'division');
    }

    /** Independent units are standalone; exposed for symmetry. */
    public function units(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_department_id')
            ->where('unit_type', 'independent_unit');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'department_id');
    }

    /** Staff placed in this division (organizational placement only). */
    public function divisionEmployees(): HasMany
    {
        return $this->hasMany(Employee::class, 'division_id');
    }

    /** Staff placed in this independent unit (organizational placement only). */
    public function unitEmployees(): HasMany
    {
        return $this->hasMany(Employee::class, 'unit_id');
    }

    public function headGrant(): HasOne
    {
        return $this->hasOne(EmployeeRole::class, 'department_id')
            ->whereIn('role_id', ['DEPT_HEAD', 'ZONAL_HEAD'])
            ->latestOfMany('id');
    }

    public static function validateNoCircularParent(?Department $potentialChild, ?Department $potentialParent): bool
    {
        if (! $potentialChild || ! $potentialParent) {
            return true;
        }

        $visited = [];
        $current = $potentialParent;
        while ($current !== null) {
            if ($current->id === $potentialChild->id) {
                return false;
            }
            if (in_array($current->id, $visited)) {
                return false;
            }
            $visited[] = $current->id;
            $current = $current->parent;
        }
        return true;
    }

    public function ancestorChain(): \Generator
    {
        $current = $this;
        while ($current !== null) {
            yield $current;
            $current = $current->parent;
        }
    }
}