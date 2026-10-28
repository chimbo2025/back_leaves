<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_waivers', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->string('leave_type_id');
            $table->string('leave_policy_id')->nullable();
            $table->date('leave_cycle_start')->nullable();
            $table->date('leave_cycle_end')->nullable();
            $table->string('requested_action');
            $table->text('reason')->nullable();
            $table->string('requested_by')->nullable();
            $table->string('status')->default('draft');
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->json('effect_json')->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types');
            $table->foreign('leave_policy_id')->references('id')->on('leave_policies')->nullOnDelete();
            $table->foreign('requested_by')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_waivers');
    }
};
