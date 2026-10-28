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
        'unit_type', 'is_active', 'is_independent', 'sort_order',
        'reports_directly_to_leadership', 'effective_from', 'effective_to',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_independent' => 'boolean',
        'reports_directly_to_leadership' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    protected function casts(): array
    {
        return [
            'reports_directly_to_leadership' => 'boolean',
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

    /**
     * Validate that assigning this department as a parent would not create a
     * circular relationship (a department cannot be its own ancestor).
     */
    public static function validateNoCircularParent(?Department $potentialChild, ?Department $potentialParent): bool
    {
        if (! $potentialChild || ! $potentialParent) {
            return true;
        }

        $visited = [];
        $current = $potentialParent;
        while ($current !== null) {
            if ($current->id === $potentialChild->id) {
                return false; // circular detected
            }
            if (in_array($current->id, $visited)) {
                return false; // already visited (should not happen with proper DB constraints)
            }
            $visited[] = $current->id;
            $current = $current->parent;
        }
        return true;
    }

    /**
     * Return the full parent chain including this department.
     */
    public function ancestorChain(): \Generator
    {
        $current = $this;
        while ($current !== null) {
            yield $current;
            $current = $current->parent;
        }
    }
}
