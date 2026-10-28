<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two kinds of cases exist (frontend docket-continuity + blueprint court
     * calendar awareness). `kind` distinguishes them:
     *   - 'court_case': a live court matter owned by a prosecutor; powers the
     *     final-approval handover gate (canFinalApprove) and hearing conflicts.
     *   - 'docket': a per-request file-following record mirroring the frontend
     *     "Case Handovers" view; tracks which approval role holds the file.
     */
    public function up(): void
    {
        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->string('ref')->unique(); // court ref (e.g. CR-2026-0142) or request ref
            $table->string('kind')->default('docket'); // court_case|docket
            $table->unsignedBigInteger('request_id')->nullable();
            $table->string('assigned_prosecutor_id');
            $table->date('next_hearing_date')->nullable();
            $table->string('status')->default('active'); // active|closed
            $table->string('owner_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('request_id')->references('id')->on('leave_requests')->nullOnDelete();
            $table->foreign('assigned_prosecutor_id')->references('id')->on('employees');
            $table->foreign('owner_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cases');
    }
};
