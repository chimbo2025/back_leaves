<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowDefinition extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'workflow_title', 'leave_type_id', 'version', 'min_duration_days', 'max_duration_days',
        'zone_id', 'department_id',
        'effective_from', 'effective_to', 'created_by', 'approved_by', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'is_active' => 'boolean',
            'version' => 'integer',
            'min_duration_days' => 'integer',
            'max_duration_days' => 'integer',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /** Organizational placement nodes this workflow applies to (authoritative set). */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'workflow_definition_departments', 'workflow_def_id', 'department_id');
    }

    /**
     * The union of the multi-scope pivot and the legacy single department_id
     * column. The two are kept in sync by the controller (first selection
     * mirrors the pivot), so the union is a safe superset for both new and
     * pre-migration rows.
     *
     * @return list<string>
     */
    public function departmentIds(): array
    {
        $ids = [];

        // The legacy column mirrors the FIRST selection of the pivot set, so
        // unioning it first keeps departmentIds() deterministic (pivot row
        // order from the database is not guaranteed).
        if ($this->department_id) {
            $ids[] = $this->department_id;
        }

        $ids = array_merge(
            $ids,
            $this->relationLoaded('departments')
                ? $this->departments->pluck('id')->all()
                : $this->departments()->pluck('departments.id')->all(),
        );

        return array_values(array_unique(array_filter(array_map('strval', $ids))));
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class, 'workflow_def_id')->orderBy('step_order');
    }
}
