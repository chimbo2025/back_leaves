<?php

namespace App\Support;

use App\Mail\LeaveNotificationMail;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Mail;

class LaravelNotificationMailer implements NotificationMailer
{
    public function send(NotificationDelivery $delivery): ?string
    {
        Mail::to($delivery->recipient)->send(new LeaveNotificationMail($delivery->notification));

        // The array/log transports do not surface a message id; a real SMTP
        // transport would, and the job records whatever is returned.
        return null;
    }
}
