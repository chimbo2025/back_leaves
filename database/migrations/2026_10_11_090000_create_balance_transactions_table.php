<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only balance-effect ledger (Phase 2 balance decision).
 *
 * The application already computes entitlement and usage dynamically
 * (PolicyService::balanceFor). This table records only the balance effects that
 * are NOT already represented by that dynamic calculation — in Phase 2, approved
 * waiver/deferral effects. Posted rows are immutable: corrections are made with
 * a reversal transaction, never by editing or deleting a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->string('leave_type_id');
            $table->date('leave_cycle_start')->nullable();
            $table->date('leave_cycle_end')->nullable();
            $table->string('transaction_type');
            $table->decimal('amount', 8, 2);
            $table->string('direction');
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->unsignedBigInteger('leave_request_id')->nullable();
            $table->unsignedBigInteger('leave_waiver_id')->nullable();
            $table->string('policy_id')->nullable();
            $table->string('reason_code')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata_json')->nullable();
            $table->string('idempotency_key')->unique();
            $table->unsignedBigInteger('reversed_transaction_id')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types');
            $table->foreign('leave_request_id')->references('id')->on('leave_requests')->nullOnDelete();
            $table->foreign('leave_waiver_id')->references('id')->on('leave_waivers')->nullOnDelete();
            $table->foreign('policy_id')->references('id')->on('leave_policies')->nullOnDelete();
            $table->foreign('reversed_transaction_id')->references('id')->on('balance_transactions')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['employee_id', 'leave_type_id']);
            $table->index('transaction_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_transactions');
    }
};
