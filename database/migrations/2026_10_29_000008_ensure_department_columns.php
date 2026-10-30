<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization refactor — guarantee the department columns the division/unit
 * endpoints read actually exist.
 *
 * On databases created before `2026_09_09_210002_create_departments_table` grew
 * its `code` (and sibling) columns, that migration is already marked as run, so
 * the columns were never added. The division/unit validation and serialisers
 * reference them, which produced:
 *
 *   SQLSTATE[42S22]: Column not found: 1054 Unknown column 'code' in 'where clause'
 *
 * This migration is purely additive and idempotent: it adds only the columns
 * that are missing, never drops or renames anything, and never touches rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'code' => fn (Blueprint $t) => $t->string('code')->nullable(),
            'name_sw' => fn (Blueprint $t) => $t->string('name_sw')->nullable(),
            'zone_id' => fn (Blueprint $t) => $t->string('zone_id')->nullable(),
            'parent_department_id' => fn (Blueprint $t) => $t->string('parent_department_id')->nullable(),
            'unit_type' => fn (Blueprint $t) => $t->string('unit_type')->default('department'),
            'is_active' => fn (Blueprint $t) => $t->boolean('is_active')->default(true),
            'sort_order' => fn (Blueprint $t) => $t->integer('sort_order')->default(0),
            'reports_directly_to_leadership' => fn (Blueprint $t) => $t->boolean('reports_directly_to_leadership')->default(false),
            'effective_from' => fn (Blueprint $t) => $t->date('effective_from')->nullable(),
            'effective_to' => fn (Blueprint $t) => $t->date('effective_to')->nullable(),
        ];

        $missing = array_keys(array_filter(
            $columns,
            fn (string $column) => ! Schema::hasColumn('departments', $column),
            ARRAY_FILTER_USE_KEY,
        ));

        if ($missing === []) {
            return;
        }

        Schema::table('departments', function (Blueprint $table) use ($columns, $missing) {
            foreach ($missing as $column) {
                $columns[$column]($table);
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: these columns back live division/unit records.
        // Dropping them would destroy data this migration is meant to preserve.
    }
};
