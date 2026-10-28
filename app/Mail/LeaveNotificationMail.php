<?php

namespace App\Mail;

use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Phase 4 — the email body for an in-system notification.
 *
 * Rendered from the same `message` column the in-system channel shows, so the
 * two channels can never drift into telling the recipient different things.
 */
class LeaveNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Notification $notification) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'DPP Leave — '.Str::headline($this->notification->event_type),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: nl2br(e($this->notification->message)),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
