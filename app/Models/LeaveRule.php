<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveRule extends Model
{
    protected $fillable = [
        'leave_policy_id', 'rule_key', 'operator', 'value_json', 'condition_json',
        'priority', 'is_inherited', 'inherited_from_rule_id', 'overridden', 'source_reference',
    ];

    protected function casts(): array
    {
        return [
            'value_json' => 'array',
            'condition_json' => 'array',
            'is_inherited' => 'boolean',
            'overridden' => 'boolean',
        ];
    }

    public function policy()
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }
}
