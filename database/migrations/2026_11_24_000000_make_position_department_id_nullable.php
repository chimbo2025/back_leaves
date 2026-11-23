<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Positions may exist without a department (e.g. organization-wide roles).
 * Make department_id nullable so a position can be created with "none" selected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('positions', 'department_id')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->string('department_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('department_id')->nullable(false)->change();
        });
    }
};
