<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blueprint §2 — dynamic approval-role catalog.
     *
     * Replaces hard-coded role strings in workflow steps with
     * `approval_role_id` FK references. Administrators create and
     * maintain these roles through the API; they are independent of
     * the legacy `roles` table permission matrix.
     *
     * Resolution types:
     *  'employee_approval_roles' — resolve from employee_approval_roles table
     *  'supervisor'              — resolve from reporting lines (supervisor)
     *  'both'                    — try employee_approval_roles first, then supervisor
     */
    public function up(): void
    {
        Schema::create('approval_roles', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('name_sw')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system_protected')->default(false);
            $table->string('resolution_type')->default('employee_approval_roles'); // supervisor | employee_approval_roles | both
            $table->timestamps();

            $table->index(['code', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_roles');
    }
};