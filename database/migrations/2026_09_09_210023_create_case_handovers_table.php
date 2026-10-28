<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blueprint §1.4 / Part 3 handover state machine:
     * proposed -> accepted -> completed (supervisor/zonal capacity review
     * recorded via reviewed_by/reviewed_at); proposed -> rejected pauses the
     * leave request until a new recipient is accepted.
     */
    public function up(): void
    {
        Schema::create('case_handovers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id')->nullable();
            $table->unsignedBigInteger('case_id');
            $table->string('from_employee_id');
            $table->string('to_employee_id');
            $table->string('status')->default('proposed'); // proposed|accepted|completed|rejected
            $table->timestamp('proposed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('reviewed_by')->nullable(); // supervisor/zonal head sign-off
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('request_id')->references('id')->on('leave_requests')->cascadeOnDelete();
            $table->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();
            $table->foreign('from_employee_id')->references('id')->on('employees');
            $table->foreign('to_employee_id')->references('id')->on('employees');
            $table->foreign('reviewed_by')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_handovers');
    }
};
