<?php

namespace App\Support;

use App\Models\NotificationDelivery;

/**
 * Seam between the queued delivery job and Laravel's mailer.
 *
 * The job depends on this contract rather than the Mail facade so a test can
 * swap in a mailer that succeeds, fails, or returns a provider id without
 * touching the transport. The default implementation is bound in
 * AppServiceProvider.
 */
interface NotificationMailer
{
    /**
     * Deliver one email notification. Returns a provider message id when the
     * transport exposes one, otherwise null.
     *
     * @throws \Throwable when the transport rejects the message
     */
    public function send(NotificationDelivery $delivery): ?string;
}
