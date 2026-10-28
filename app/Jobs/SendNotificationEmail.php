<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Support\NotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Phase 4 — deliver one email notification, once.
 *
 * The delivery row is the unit of work, so retries update the same row instead
 * of minting a new one. A delivery already marked sent is a no-op, which is what
 * makes the queue's at-least-once behaviour safe: running this job twice never
 * sends twice.
 *
 * Failure is recorded on the row (status, failed_at, reason, attempts) before
 * the exception is rethrown so the queue's retry policy still applies, and the
 * final attempt leaves a row an operator can see and act on.
 */
class SendNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $deliveryId)
    {
        $this->tries = (int) config('leave.notifications.email_retry_attempts', 3);
    }

    public int $tries;

    public function handle(NotificationMailer $mailer): void
    {
        $delivery = NotificationDelivery::with('notification')->find($this->deliveryId);

        if (! $delivery) {
            return;
        }

        // Idempotent: a sent delivery is never re-sent.
        if ($delivery->isSent()) {
            return;
        }

        $delivery->increment('attempts');
        $delivery->refresh();

        try {
            $messageId = $mailer->send($delivery);

            $delivery->update([
                'status' => NotificationDelivery::STATUS_SENT,
                'sent_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
                'provider_message_id' => $messageId,
            ]);
        } catch (Throwable $e) {
            $delivery->update([
                'status' => NotificationDelivery::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /** @return array<int,int> */
    public function backoff(): array
    {
        return array_values((array) config('leave.notifications.email_retry_backoff', [60, 300, 900]));
    }
}
