<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('workflow_assignments', 'assigned_employee_id')) {
                $table->string('assigned_employee_id')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        // no-op: keep nullable
    }
};
