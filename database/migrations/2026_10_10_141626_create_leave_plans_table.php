<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_plans', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->string('leave_type_id');
            $table->string('leave_policy_id')->nullable();
            $table->date('cycle_start')->nullable();
            $table->date('cycle_end')->nullable();
            $table->string('status')->default('draft');
            $table->string('selected_option')->nullable();
            $table->date('confirmed_start_date')->nullable();
            $table->date('confirmed_end_date')->nullable();
            $table->string('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('leave_request_id')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types');
            $table->foreign('leave_policy_id')->references('id')->on('leave_policies')->nullOnDelete();
            $table->foreign('confirmed_by')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('leave_request_id')->references('id')->on('leave_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_plans');
    }
};
