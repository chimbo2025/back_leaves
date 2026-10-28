<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations', function (Blueprint $table) {
            $table->string('id')->primary(); // del1, del2
            $table->string('delegator_employee_id');
            $table->string('delegate_employee_id');
            $table->string('role_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('authorized_by')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('delegator_employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('delegate_employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles');
            $table->foreign('authorized_by')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
    }
};
