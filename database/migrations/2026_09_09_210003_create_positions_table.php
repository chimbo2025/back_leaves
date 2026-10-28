<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blueprint (2): positions are purely STRUCTURAL (title, department, zone).
     * approval_tier / is_final_authority live on roles; tier kept for display parity.
     */
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->string('id')->primary(); // p1..p12
            $table->string('title');
            $table->string('department_id');
            $table->string('zone_id')->nullable();
            $table->unsignedTinyInteger('tier')->nullable();
            $table->boolean('is_final_authority')->default(false);
            $table->timestamps();

            $table->foreign('department_id')->references('id')->on('departments')->cascadeOnDelete();
            $table->foreign('zone_id')->references('id')->on('zones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
