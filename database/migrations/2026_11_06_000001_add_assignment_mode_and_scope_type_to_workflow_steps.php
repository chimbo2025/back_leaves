<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('workflow_steps', 'assignment_mode')) {
                $table->string('assignment_mode')->default('assigned_approver');
            }
            if (! Schema::hasColumn('workflow_steps', 'scope_type')) {
                $table->string('scope_type')->default('requester_department');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            foreach (['assignment_mode', 'scope_type'] as $column) {
                if (Schema::hasColumn('workflow_steps', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
