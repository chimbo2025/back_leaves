<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave payment cases (Phase 5).
 *
 * No salary, allowance, compensation or payment table exists anywhere in this
 * schema. `employees` carries only `payroll_no` (an identifier, not money) and
 * the only financial table is `balance_transactions` — a leave-DAY ledger, not
 * a ledger of money. Phase 5 therefore introduces exactly one new table rather
 * than inventing a parallel payroll system:
 *
 *   leave_payment_cases   the lifecycle of a single payment arising from leave
 *
 * The salary basis is supplied by the authorised creator at case creation and
 * frozen into the row (and into calculation_snapshot). Nothing here reads an
 * employee salary from elsewhere, because there is nowhere to read it from; the
 * open policy decision is recorded in docs/phase5-payments.md.
 *
 * Everything that a reviewer or an auditor later needs is snapshotted on the
 * row rather than re-derived: eligibility_snapshot is the eligibility result at
 * the moment it was evaluated, calculation_snapshot is the arithmetic (basis,
 * divisor, allowances, formula) at the moment it was calculated. A later policy
 * revision or salary correction must never silently move an agreed amount.
 *
 * A payment case is matched to at most one (leave_request_id, payment_type) so a
 * retried create cannot mint a second charge for the same leave; idempotency_key
 * additionally makes a caller-supplied retry key concrete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_payment_cases', function (Blueprint $table) {
            $table->id();

            $table->string('employee_id');
            $table->unsignedBigInteger('leave_request_id')->nullable();
            $table->string('leave_type_id');
            $table->string('policy_id')->nullable();

            $table->string('payment_type')->default('leave_encashment');
            $table->string('status')->default('pending');

            // Snapshot of the salary basis handed in by the creator. Payroll has
            // no table to join to, so these columns ARE the source of truth for
            // the case once they are written.
            $table->string('salary_basis')->nullable();
            $table->decimal('salary_amount', 14, 2)->nullable();
            $table->json('allowances_included')->nullable();
            $table->json('allowances_excluded')->nullable();

            $table->decimal('eligible_amount', 14, 2)->nullable();
            $table->decimal('calculated_amount', 14, 2)->nullable();
            $table->string('currency', 3)->default('TZS');

            $table->json('calculation_snapshot')->nullable();
            $table->json('eligibility_snapshot')->nullable();

            // Lifecycle actors. Kept as employee ids (not users) so the history
            // survives a user being revoked and mirrors the rest of the schema.
            $table->string('created_by')->nullable();
            $table->string('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->string('hr_reviewed_by')->nullable();
            $table->timestamp('hr_reviewed_at')->nullable();

            $table->string('sent_to_accounting_by')->nullable();
            $table->timestamp('sent_to_accounting_at')->nullable();
            $table->timestamp('accounting_notified_at')->nullable();

            $table->timestamp('processing_at')->nullable();
            $table->string('processed_by')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->string('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->string('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->string('idempotency_key')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('leave_request_id')->references('id')->on('leave_requests')->nullOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->cascadeOnDelete();
            $table->foreign('policy_id')->references('id')->on('leave_policies')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('submitted_by')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('hr_reviewed_by')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('sent_to_accounting_by')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('processed_by')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('rejected_by')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('employees')->nullOnDelete();

            // One charge per (request, payment type). NULL leave_request_id (an
            // ad-hoc case with no underlying request) is treated as distinct by
            // every supported engine, so those are not collapsed together.
            $table->unique(['leave_request_id', 'payment_type']);
            $table->unique('idempotency_key');
            $table->index(['employee_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_payment_cases');
    }
};
