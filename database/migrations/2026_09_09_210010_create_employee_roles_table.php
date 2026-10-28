<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blueprint (2) §1.1 — the actual, current roles an employee holds.
     * source: position_default | manual_grant | acting. `department_id` is a
     * pragmatic extension mirroring blueprint's department-scoped workflows,
     * so DEPT_HEAD can be scoped to an exact department (not just a zone).
     */
    public function up(): void
    {
        Schema::create('employee_roles', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->string('role_id');
            $table->string('department_id')->nullable();
            $table->string('scope_zone_id')->nullable(); // NULL = organization-wide scope
            $table->string('source')->default('position_default');
            $table->string('granted_by')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'role_id', 'department_id', 'scope_zone_id'], 'employee_roles_identity');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('scope_zone_id')->references('id')->on('zones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_roles');
    }
};
