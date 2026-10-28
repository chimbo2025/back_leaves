<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital approval signatures (Phase 6).
 *
 * One row per approval action, capturing the actor's signature at the moment
 * of decision. The signature is a hash of the decision payload; the raw
 * signature value is never stored — only its cryptographic digest.
 *
 * This table is append-only: once written, a signature row is never modified.
 * A re-approval after handover acceptance creates a new row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_signatures', function (Blueprint $table) {
            $table->id();

            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_action_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_id'); // employee_id who signed

            // The approval action this signature covers.
            $table->enum('approval_action', ['approved', 'rejected', 'returned']);

            // Signature metadata.
            $table->enum('signature_type', ['digital', 'wet_ink_reference'])->default('digital');
            $table->string('signature_value')->nullable(); // opaque reference (e.g. HSM token ref), NOT the raw key
            $table->string('signature_hash'); // SHA-256 of the canonical payload
            $table->timestamp('signed_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata_json')->nullable(); // policy_version, return_date, etc.

            $table->timestamps();

            $table->index(['leave_request_id', 'actor_id']);
            $table->index('approval_action_id');
            $table->index('signature_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_signatures');
    }
};
