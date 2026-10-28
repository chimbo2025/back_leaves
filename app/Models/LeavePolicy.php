<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeavePolicy extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'leave_type_id', 'version', 'max_days_per_year', 'max_days_per_request',
        'min_tenure_months', 'min_notice_days', 'requires_documents', 'required_docs',
        'carry_over_max', 'carry_over_expiry_month', 'allow_half_day', 'notes',
        'status', 'source_document', 'regulation_reference', 'is_inherited', 'parent_policy_id', 'priority',
        'effective_from', 'effective_to', 'created_by', 'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'required_docs' => 'array',
            'requires_documents' => 'boolean',
            'allow_half_day' => 'boolean',
            'max_days_per_year' => 'float',
            'max_days_per_request' => 'float',
            'carry_over_max' => 'float',
            'is_inherited' => 'boolean',
            'priority' => 'integer',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
