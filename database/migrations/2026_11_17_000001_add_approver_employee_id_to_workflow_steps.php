<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit approver for a workflow step.
 *
 * The Workflow Builder now stores the actual employee chosen from the holders
 * of the step's approval role. The column is nullable so:
 *   - existing definitions (which resolve dynamically through their role) keep
 *     working unchanged, and
 *   - the migration is backward compatible on both SQLite and MySQL.
 *
 * No foreign key is declared on purpose: SQLite cannot add one without a table
 * rebuild, and a cascade/set-null would silently rewrite live approval steps if
 * an employee row were ever removed. The API validates the employee and the
 * employee/role combination on every write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('workflow_steps', 'approver_employee_id')) {
                $table->string('approver_employee_id')->nullable()->after('approval_role_id');
                $table->index('approver_employee_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            if (Schema::hasColumn('workflow_steps', 'approver_employee_id')) {
                $table->dropIndex(['approver_employee_id']);
                $table->dropColumn('approver_employee_id');
            }
        });
    }
};
