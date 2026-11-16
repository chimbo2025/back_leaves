<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-scope workflows.
 *
 * A workflow definition may now apply to any number of organizational
 * placement nodes (departments, divisions and independent units — all rows of
 * `departments`). The pivot is the authoritative set; the legacy single
 * `workflow_definitions.department_id` column is kept and mirrored (first
 * selection of the set) so older readers keep working unchanged.
 *
 * Backfill is an insertion into a freshly created, empty table derived from
 * data already present; no existing rows are modified or dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definition_departments', function (Blueprint $table) {
            $table->string('workflow_def_id');
            $table->string('department_id');

            // Composite primary key — hosted MySQL 8.4 enforces sql_require_primary_key.
            $table->primary(['workflow_def_id', 'department_id']);
            $table->index('department_id');

            $table->foreign('workflow_def_id')
                ->references('id')
                ->on('workflow_definitions')
                ->cascadeOnDelete();
            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->cascadeOnDelete();
        });

        // One-time backfill: every existing single-department scope becomes a
        // one-row pivot so production rows resolve exactly as before.
        DB::statement('INSERT INTO workflow_definition_departments (workflow_def_id, department_id)
            SELECT id, department_id FROM workflow_definitions WHERE department_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_definition_departments');
    }
};
