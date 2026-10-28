<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leave_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('leave_policy_id')->nullable()->index();
            $table->string('leave_type_id')->index();
            $table->string('document_type');
            $table->boolean('required')->default(true);
            $table->json('condition_json')->nullable();
            $table->string('verification_role')->nullable();
            $table->string('sensitivity')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_document_requirements');
    }
};
