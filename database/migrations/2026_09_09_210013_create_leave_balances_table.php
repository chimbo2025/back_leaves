<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->string('leave_type_id');
            $table->unsignedInteger('year');
            $table->decimal('accrued_days', 6, 2);
            $table->decimal('carried_over_days', 6, 2)->default(0);
            $table->decimal('used_days', 6, 2)->default(0);
            $table->date('expiry_date')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year']);
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
