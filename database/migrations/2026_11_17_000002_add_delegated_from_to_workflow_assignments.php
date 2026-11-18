<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the original approver when a step is routed to an active delegate.
 *
 * `assigned_employee_id` remains the person who must act (the delegate), while
 * `delegated_from_employee_id` keeps the selected approver for the audit trail.
 * Nullable so every pre-existing assignment is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('workflow_assignments', 'delegated_from_employee_id')) {
                $table->string('delegated_from_employee_id')->nullable()->after('assigned_employee_id');
                $table->index('delegated_from_employee_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workflow_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('workflow_assignments', 'delegated_from_employee_id')) {
                $table->dropIndex(['delegated_from_employee_id']);
                $table->dropColumn('delegated_from_employee_id');
            }
        });
    }
};
