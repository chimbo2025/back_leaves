<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — per-channel delivery tracking for every notification.
 *
 * A `notifications` row is the in-system message an employee reads; a delivery
 * row is one attempt to get some event to some recipient over some channel
 * (database or email). Splitting them means a failed email can be retried
 * without re-reading or duplicating the in-system notification, and the audit
 * question "did X actually receive this?" has an answer per channel.
 *
 * `idempotency_key` is unique so a scheduled reminder that runs twice cannot
 * deliver twice. The base key is
 *   employee:{id}:event:{event}:reference:{id}:date:{date}
 * with the channel appended, so each channel is idempotent independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->string('channel'); // database|email
            // Employee id for the database channel; email address for email.
            $table->string('recipient');
            $table->string('status')->default('queued'); // queued|sent|failed|skipped
            $table->string('provider_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['notification_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
