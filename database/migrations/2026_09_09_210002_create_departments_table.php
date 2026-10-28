<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blueprint (2) §1.1 — departments with expandable hierarchy.
     * Supports: office, department, division, independent_unit,
     * coordination_office, regional_office, executive_leadership.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->string('id')->primary(); // d1..d8
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('name_sw')->nullable();
            $table->string('zone_id')->nullable();
            $table->string('parent_department_id')->nullable();
            $table->string('unit_type')->default('department'); // department|division|independent_unit|office|coordination_office|regional_office|executive_leadership
            $table->boolean('is_active')->default(true);
            $table->boolean('is_independent')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('reports_directly_to_leadership')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->foreign('zone_id')->references('id')->on('zones')->cascadeOnDelete();
            $table->foreign('parent_department_id')->references('id')->on('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
