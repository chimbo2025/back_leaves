<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * leave_requests — statuses mirror frontend Status union:
     * draft|submitted|under_review|returned_for_correction|approved|rejected|
     * withdrawn|paused_pending_handover
     */
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->string('ref')->unique(); // LVE-2026-0147
            $table->string('employee_id');
            $table->string('leave_type_id');
            $table->string('policy_id')->nullable(); // version pinned at submit time
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('days');
            $table->boolean('is_half_day')->default(false);
            $table->boolean('is_emergency_exception')->default(false);
            $table->text('exception_reason')->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('draft');
            $table->string('current_owner_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types');
            $table->foreign('policy_id')->references('id')->on('leave_policies')->nullOnDelete();
            $table->foreign('current_owner_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
