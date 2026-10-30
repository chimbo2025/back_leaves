<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Organization refactor — normalise legacy standalone units.
 *
 * Earlier builds created rows with `unit_type = 'unit'`. The refactor names
 * standalone placement rows `independent_unit` (the value the Units tab, the
 * validators and the resolver all expect) and makes them independent of any
 * department or division parent.
 *
 * This migration only relabels existing rows and clears a placement field the
 * new model no longer uses. No row is deleted and no name/code changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('departments')) {
            return;
        }

        DB::table('departments')
            ->where('unit_type', 'unit')
            ->update([
                'unit_type' => 'independent_unit',
                'parent_department_id' => null,
            ]);
    }

    public function down(): void
    {
        // Intentionally empty: restoring the old label would make the rows
        // invisible to the Units tab again.
    }
};
