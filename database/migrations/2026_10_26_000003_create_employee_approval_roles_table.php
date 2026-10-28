<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_approval_roles', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('employee_id');
            $table->string('approval_role_id');
            $table->string('organizational_unit_id')->nullable();
            $table->string('zone_id')->nullable();
            $table->boolean('is_primary')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('source')->default('manual_grant');
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('approval_role_id')->references('id')->on('approval_roles')->cascadeOnDelete();
            $table->foreign('organizational_unit_id')->references('id')->on('departments')->nullOnDelete();
            $table->index(['employee_id', 'approval_role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_approval_roles');
    }
};