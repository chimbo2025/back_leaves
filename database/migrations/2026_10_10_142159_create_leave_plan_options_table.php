<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_plan_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('leave_plan_id');
            $table->unsignedInteger('option_number');
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('days');
            $table->decimal('score', 5, 2)->nullable();
            $table->json('score_breakdown_json')->nullable();
            $table->string('availability_status')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('leave_plan_id')->references('id')->on('leave_plans')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_plan_options');
    }
};
