<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Idempotent: a previous partially-applied run (or a hand-patched live
        // database) may already carry some of these columns. Guard each add so
        // re-running the migration converges instead of failing on 1060.
        Schema::table('leave_policies', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_policies', 'status')) {
                $table->string('status')->default('active')->after('notes');
            }
            if (! Schema::hasColumn('leave_policies', 'source_document')) {
                $table->string('source_document')->nullable()->after('status');
            }
            if (! Schema::hasColumn('leave_policies', 'regulation_reference')) {
                $table->string('regulation_reference')->nullable()->after('source_document');
            }
            if (! Schema::hasColumn('leave_policies', 'is_inherited')) {
                $table->boolean('is_inherited')->default(false)->after('regulation_reference');
            }
            if (! Schema::hasColumn('leave_policies', 'parent_policy_id')) {
                $table->string('parent_policy_id')->nullable()->after('is_inherited');
            }
            if (! Schema::hasColumn('leave_policies', 'priority')) {
                $table->unsignedTinyInteger('priority')->default(0)->after('parent_policy_id');
            }
        });

        $indexes = collect(Schema::getIndexes('leave_policies'))->pluck('name')->all();
        if (! in_array('leave_policies_type_status_dates_idx', $indexes, true)) {
            Schema::table('leave_policies', function (Blueprint $table) {
                // Short explicit name: MySQL caps identifiers at 64 chars and the
                // conventional auto-name for these four columns is 76 chars.
                $table->index(['leave_type_id', 'status', 'effective_from', 'effective_to'], 'leave_policies_type_status_dates_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_policies', function (Blueprint $table) {
            $table->dropIndex('leave_policies_type_status_dates_idx');
            $table->dropColumn(['status', 'source_document', 'regulation_reference', 'is_inherited', 'parent_policy_id', 'priority']);
        });
    }
};
