<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of the workflow admin simplification:
 *
 *   - workflow_title — required human identifier for a chain. Existing rows are
 *     back-filled as "{Leave Type} Workflow v{version}" so nothing is left
 *     anonymous and the list/detail pages can use a real name.
 *   - max_duration_days — optional upper bound for "Advanced Duration Rule".
 *     NULL keeps the historical open-ended meaning (minimum and above), so
 *     every existing definition behaves exactly as before.
 *
 * The column stays nullable at the database level on purpose: legacy writers
 * (and the seeded fixtures) predate the required-title rule. Required-ness is
 * enforced by StoreWorkflowRequest for every new definition, while the
 * migration guarantees every pre-existing row receives a generated title.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workflow_definitions', 'workflow_title')) {
            Schema::table('workflow_definitions', function (Blueprint $table) {
                $table->string('workflow_title', 150)->nullable()->after('id');
                $table->index('workflow_title');
            });
        }

        if (! Schema::hasColumn('workflow_definitions', 'max_duration_days')) {
            Schema::table('workflow_definitions', function (Blueprint $table) {
                $table->unsignedInteger('max_duration_days')->nullable()->after('min_duration_days');
            });
        }

        // Back-fill a sensible title for every pre-existing definition. The
        // leave type name comes from the catalogue; when the pointer is stale
        // the id is still better than an unnamed chain.
        $rows = DB::table('workflow_definitions')
            ->leftJoin('leave_types', 'leave_types.id', '=', 'workflow_definitions.leave_type_id')
            ->whereNull('workflow_definitions.workflow_title')
            ->orWhere('workflow_definitions.workflow_title', '')
            ->select([
                'workflow_definitions.id',
                'workflow_definitions.version',
                'leave_types.name as leave_type_name',
                'workflow_definitions.leave_type_id',
            ])
            ->get();

        foreach ($rows as $row) {
            $label = trim((string) ($row->leave_type_name ?: $row->leave_type_id));
            $title = ($label !== '' ? $label : 'Leave') . ' Workflow v' . (int) $row->version;

            DB::table('workflow_definitions')
                ->where('id', $row->id)
                ->update(['workflow_title' => mb_substr($title, 0, 150)]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('workflow_definitions', 'max_duration_days')) {
            Schema::table('workflow_definitions', function (Blueprint $table) {
                $table->dropColumn('max_duration_days');
            });
        }

        if (Schema::hasColumn('workflow_definitions', 'workflow_title')) {
            Schema::table('workflow_definitions', function (Blueprint $table) {
                $table->dropIndex(['workflow_title']);
                $table->dropColumn('workflow_title');
            });
        }
    }
};
