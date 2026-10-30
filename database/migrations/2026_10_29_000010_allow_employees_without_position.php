<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization refactor — positions are optional job-title assignments.
 *
 * Deleting a position must preserve employees and clear only their
 * `position_id`. Make the FK nullable and use ON DELETE SET NULL as a database
 * safety net for deletes outside the application controller as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees') || ! Schema::hasColumn('employees', 'position_id')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['position_id']);
            $table->string('position_id')->nullable()->change();
            $table->foreign('position_id')
                ->references('id')
                ->on('positions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Intentionally empty. Existing employees may now legitimately have a
        // null position; a rollback must not fail or invent a replacement title.
    }
};
