<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->string('id')->primary(); // e1..e12 (frontend parity)
            $table->string('user_id')->nullable();
            $table->string('full_name');
            $table->string('initials')->nullable();
            $table->string('position_id');
            $table->string('department_id');
            $table->string('zone_id');
            $table->string('gender', 10)->nullable(); // M|F — gender-disaggregated reports only
            $table->string('email')->nullable();
            $table->date('hire_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('position_id')->references('id')->on('positions');
            $table->foreign('department_id')->references('id')->on('departments');
            $table->foreign('zone_id')->references('id')->on('zones');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
