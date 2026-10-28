<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — per-user notification preferences.
 *
 * One row per (user, event_type). Absence of a row means "use the defaults"
 * (both channels on), so the table only stores deliberate choices. Mandatory
 * event types ignore these rows entirely — see
 * NotificationPreferenceService::MANDATORY_EVENTS.
 *
 * `timing_config_json` lets a user offset a scheduled reminder (e.g. "email me
 * 3 days before my leave starts instead of 2"). Parsed leniently; unknown keys
 * fall back to config('leave.notifications.*').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('event_type');
            $table->boolean('email_enabled')->default(true);
            $table->boolean('database_enabled')->default(true);
            $table->string('language')->default('en');
            $table->json('timing_config_json')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'event_type']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
