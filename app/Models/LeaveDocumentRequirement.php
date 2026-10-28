<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveDocumentRequirement extends Model
{
    protected $fillable = [
        'leave_policy_id', 'leave_type_id', 'document_type', 'required',
        'condition_json', 'verification_role', 'sensitivity', 'active',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'active' => 'boolean',
            'condition_json' => 'array',
        ];
    }

    public function policy()
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
