<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeavePlanOption extends Model
{
    protected $fillable = [
        'leave_plan_id', 'option_number', 'start_date', 'end_date',
        'days', 'score', 'score_breakdown_json', 'availability_status', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'score_breakdown_json' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(LeavePlan::class, 'leave_plan_id');
    }
}
