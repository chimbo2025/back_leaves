<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resolved person snapshotted per assignment (role_at_time for audit).
     */
    public function up(): void
    {
        Schema::create('workflow_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('step_id')->nullable(); // null = system-synthesized FINAL_AUTHORITY step
            $table->string('assigned_employee_id');
            $table->string('assigned_role_id');
            $table->string('status')->default('pending'); // pending|done|escalated|skipped
            $table->timestamp('due_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('request_id')->references('id')->on('leave_requests')->cascadeOnDelete();
            $table->foreign('step_id')->references('id')->on('workflow_steps')->nullOnDelete();
            $table->foreign('assigned_employee_id')->references('id')->on('employees');
            $table->foreign('assigned_role_id')->references('id')->on('roles');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('workflow_assignments') && Schema::hasColumn('workflow_assignments', 'step_id')) {
            Schema::table('workflow_assignments', function (Blueprint $table) {
                $table->dropForeign(['step_id']);
            });
        }
        Schema::dropIfExists('workflow_assignments');
    }
};
