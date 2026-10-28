<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->string('file_ref'); // encrypted storage pointer, not raw path
            $table->string('document_type')->nullable(); // medical_cert|study_admission|birth_doc
            $table->string('name')->nullable();
            $table->string('size')->nullable();
            $table->string('uploaded_by_user_id')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->foreign('request_id')->references('id')->on('leave_requests')->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_documents');
    }
};
