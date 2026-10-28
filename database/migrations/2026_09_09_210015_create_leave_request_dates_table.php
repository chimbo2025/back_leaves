<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_dates', function (Blueprint $table) {
            $table->unsignedBigInteger('request_id');
            $table->date('the_date');
            $table->decimal('day_fraction', 3, 2)->default(1.0); // 0.5 for half-day
            $table->primary(['request_id', 'the_date']);

            $table->foreign('request_id')->references('id')->on('leave_requests')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_dates');
    }
};
