<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization refactor — employees may be placed in a division and/or an
 * independent unit in addition to their mandatory department and optional zone.
 *
 * Divisions and units are `departments` rows distinguished by `unit_type`, so
 * these are plain string pointers. They are organizational placement only and
 * never participate in workflow resolution (workflow depends on department).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'division_id')) {
                $table->string('division_id')->nullable()->index();
            }
            if (! Schema::hasColumn('employees', 'unit_id')) {
                $table->string('unit_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            foreach (['division_id', 'unit_id'] as $column) {
                if (Schema::hasColumn('employees', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
