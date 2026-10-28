<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned policy engine (blueprint §1.2): never edit a row in place,
     * insert a new version. policy_version pinned onto leave_requests at submit.
     */
    public function up(): void
    {
        Schema::create('leave_policies', function (Blueprint $table) {
            $table->string('id')->primary(); // pol1, pol1_v1, pol1_v3, pol2...
            $table->string('leave_type_id');
            $table->unsignedInteger('version');
            $table->decimal('max_days_per_year', 5, 1)->nullable();
            $table->decimal('max_days_per_request', 5, 1)->nullable();
            $table->unsignedInteger('min_tenure_months')->default(0);
            $table->unsignedInteger('min_notice_days')->default(0);
            $table->boolean('requires_documents')->default(false);
            $table->json('required_docs')->nullable();
            $table->decimal('carry_over_max', 5, 1)->default(0);
            $table->unsignedTinyInteger('carry_over_expiry_month')->nullable();
            $table->boolean('allow_half_day')->default(false);
            $table->text('notes')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('created_by')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamps();

            $table->unique(['leave_type_id', 'version']);
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_policies');
    }
};
