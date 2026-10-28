<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->string('id')->primary(); // lt1..lt7
            $table->string('code')->unique(); // ANNUAL, SICK...
            $table->string('name');
            $table->string('swahili')->nullable();
            $table->string('short')->nullable();
            $table->boolean('is_paid_default')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
