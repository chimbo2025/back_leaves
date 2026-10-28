<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveReason extends Model
{
    protected $fillable = [
        'leave_type_id', 'code', 'name', 'description', 'maximum_days',
        'period_type', 'requires_document', 'active',
    ];

    protected function casts(): array
    {
        return [
            'requires_document' => 'boolean',
            'active' => 'boolean',
            'maximum_days' => 'integer',
        ];
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
