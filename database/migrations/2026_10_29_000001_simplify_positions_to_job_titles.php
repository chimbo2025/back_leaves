<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization refactor — positions become simple job titles.
 *
 * Adds the descriptive/placement columns a job title needs (code, Swahili
 * title, optional division/unit placement, activation flag) and removes the
 * approval-tier fields. Approval authority is carried by roles and the
 * dynamic approval-role catalog, never by a position.
 *
 * No data is deleted: dropping `tier`/`is_final_authority` only removes
 * columns whose values were ignored by the workflow engine already.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            if (! Schema::hasColumn('positions', 'code')) {
                $table->string('code')->nullable()->unique();
            }
            if (! Schema::hasColumn('positions', 'title_sw')) {
                $table->string('title_sw')->nullable();
            }
            if (! Schema::hasColumn('positions', 'division_id')) {
                $table->string('division_id')->nullable()->index();
            }
            if (! Schema::hasColumn('positions', 'unit_id')) {
                $table->string('unit_id')->nullable()->index();
            }
            if (! Schema::hasColumn('positions', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });

        Schema::table('positions', function (Blueprint $table) {
            if (Schema::hasColumn('positions', 'tier')) {
                $table->dropColumn('tier');
            }
            if (Schema::hasColumn('positions', 'is_final_authority')) {
                $table->dropColumn('is_final_authority');
            }
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            if (! Schema::hasColumn('positions', 'tier')) {
                $table->unsignedTinyInteger('tier')->nullable();
            }
            if (! Schema::hasColumn('positions', 'is_final_authority')) {
                $table->boolean('is_final_authority')->default(false);
            }
        });

        Schema::table('positions', function (Blueprint $table) {
            foreach (['code', 'title_sw', 'division_id', 'unit_id', 'is_active'] as $column) {
                if (Schema::hasColumn('positions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
