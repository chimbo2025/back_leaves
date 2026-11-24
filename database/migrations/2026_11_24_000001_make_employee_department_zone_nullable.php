<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employees may not belong to any department or zone (e.g. organization-wide
 * roles). Make department_id and zone_id nullable so an employee can be
 * created/updated with "none" selected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'department_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('department_id')->nullable()->change();
            });
        }
        if (Schema::hasColumn('employees', 'zone_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('zone_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('department_id')->nullable(false)->change();
            $table->string('zone_id')->nullable(false)->change();
        });
    }
};
