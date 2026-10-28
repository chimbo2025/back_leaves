<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blueprint Part 4 — channel event_type list:
     * submitted|escalated|approved|rejected|returned_for_correction|
     * leave_starting_soon|leave_ending_soon|handover_proposed|handover_rejected
     * SMS messages must be is_privacy_safe (template = name + dates only).
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('recipient_employee_id');
            $table->string('channel')->default('in_system'); // in_system|email|sms
            $table->string('event_type');
            $table->string('related_entity_type')->nullable(); // leave_request|case_handover|workflow_assignment
            $table->unsignedBigInteger('related_entity_id')->nullable();
            $table->text('message');
            $table->boolean('is_privacy_safe')->default(true);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('recipient_employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
