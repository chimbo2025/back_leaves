<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('assignment_id');
            $table->string('actor_employee_id');
            $table->string('decision'); // approved|rejected|returned|reviewed
            $table->text('comments')->nullable();
            $table->unsignedBigInteger('workflow_rule_used')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->foreign('assignment_id')->references('id')->on('workflow_assignments')->cascadeOnDelete();
            $table->foreign('actor_employee_id')->references('id')->on('employees');
            $table->foreign('workflow_rule_used')->references('id')->on('workflow_steps')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_actions');
    }
};
