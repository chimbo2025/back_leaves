<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deletion-policy refactor — detachable history.
 *
 * Force-deleting a leave request must keep its evidence and its money:
 *   - leave_documents.request_id becomes nullable so documents can be detached
 *     from the deleted request instead of cascading away with it;
 *   - leave_payment_cases.employee_id becomes nullable so a payment case
 *     survives the hard deletion of the employee it was raised for.
 *
 * Both columns keep their existing foreign keys. The application detaches the
 * rows before deleting the parent, so the ON DELETE behaviour is never reached
 * for these two paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('request_id')->nullable()->change();
        });

        Schema::table('leave_payment_cases', function (Blueprint $table) {
            $table->string('employee_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Restoring NOT NULL would fail on rows detached by a force delete.
        // The columns are left nullable on rollback; the application still
        // writes them on every normal path.
    }
};
