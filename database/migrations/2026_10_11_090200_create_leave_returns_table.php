<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Return-to-work cases (Phase 3).
 *
 * A leave_returns row is opened when a leave request is finally approved. It
 * records the expected return date computed from the resolved policy at
 * approval time, then the employee's actual return and — when late — the
 * reviewer's excusal/deduction decision.
 *
 * The expected date and its calculation snapshot are frozen on the row: a
 * later policy revision must never silently move an already-agreed return date.
 * The balance effect of a late return is never written to leave_balances; it is
 * an append-only balance_transactions row posted by LateReturnService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('leave_request_id');
            $table->string('employee_id');
            $table->date('expected_return_date');
            $table->date('actual_return_date')->nullable();
            $table->string('status')->default('pending');
            $table->string('reported_by')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->text('explanation')->nullable();
            $table->string('explanation_status')->nullable();
            $table->unsignedBigInteger('supporting_document_id')->nullable();
            $table->unsignedInteger('late_days')->default(0);
            $table->unsignedInteger('excused_days')->default(0);
            $table->unsignedInteger('unauthorised_days')->default(0);
            $table->unsignedInteger('deducted_days')->default(0);
            $table->json('policy_snapshot')->nullable();
            $table->string('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('closure_reason')->nullable();
            $table->timestamps();

            $table->foreign('leave_request_id')->references('id')->on('leave_requests')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('reported_by')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('supporting_document_id')->references('id')->on('leave_documents')->nullOnDelete();
            $table->foreign('closed_by')->references('id')->on('employees')->nullOnDelete();

            // One return case per leave request keeps openForRequest() idempotent.
            $table->unique('leave_request_id');
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_returns');
    }
};
