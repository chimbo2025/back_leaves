<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    public const CHANNEL_DATABASE = 'database';

    public const CHANNEL_EMAIL = 'email';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'notification_id', 'channel', 'recipient', 'status', 'provider_message_id',
        'sent_at', 'failed_at', 'failure_reason', 'attempts', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }
}
