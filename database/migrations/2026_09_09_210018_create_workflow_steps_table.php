<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->string('workflow_def_id');
            $table->unsignedInteger('step_order');
            $table->string('role_required');
            $table->unsignedInteger('sla_days')->default(3);
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('escalation_after_sla')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['workflow_def_id', 'step_order']);
            $table->foreign('workflow_def_id')->references('id')->on('workflow_definitions')->cascadeOnDelete();
            $table->foreign('role_required')->references('id')->on('roles');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_steps');
    }
};
