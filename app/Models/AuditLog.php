<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'entity_type', 'entity_id', 'action', 'actor_user_id', 'payload',
        'reason', 'prev_hash', 'row_hash', 'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public const CHAIN_SUFFIX = 'audit';

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
