<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Official leave letters (Phase 6).
 *
 * One row per generated official document. The document itself is stored as a
 * PDF in private storage; this table holds the metadata, versioning, and the
 * content hash for integrity verification.
 *
 * A letter is generated only after the leave request reaches 'approved' status.
 * Regeneration (e.g., after a policy correction) increments document_version
 * and preserves the prior version's storage_path and content_hash.
 *
 * QR verification uses a random token stored here; the verification endpoint
 * exposes only public-safe data.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: the live database carried a hand-created copy of this
        // table (all columns, only the FK index). Converge it instead of
        // failing on 1050, adding exactly the constraints it is missing.
        if (Schema::hasTable('official_leave_letters')) {
            $indexes = collect(Schema::getIndexes('official_leave_letters'))->pluck('name')->all();

            Schema::table('official_leave_letters', function (Blueprint $table) use ($indexes) {
                if (! in_array('official_leave_letters_qr_token_unique', $indexes, true)) {
                    $table->unique('qr_token');
                }
                if (! in_array('oll_req_type_version_unique', $indexes, true)) {
                    // Short explicit name: MySQL caps identifiers at 64 chars
                    // and the conventional auto-name is 84 chars.
                    $table->unique(['leave_request_id', 'document_type', 'document_version'], 'oll_req_type_version_unique');
                }
                if (! in_array('official_leave_letters_qr_token_index', $indexes, true)) {
                    $table->index('qr_token');
                }
                if (! in_array('official_leave_letters_revoked_at_index', $indexes, true)) {
                    $table->index('revoked_at');
                }
            });

            return;
        }

        Schema::create('official_leave_letters', function (Blueprint $table) {
            $table->id();

            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();

            // Document classification.
            $table->enum('document_type', [
                'approval_letter',
                'rejection_letter',
                'cancellation_letter',
            ])->default('approval_letter');

            $table->unsignedInteger('document_version')->default(1);
            $table->unsignedInteger('template_version')->default(1);

            // Immutable snapshot of the policy and approval chain at generation time.
            $table->json('policy_snapshot');
            $table->json('approval_snapshot');

            // Integrity.
            $table->string('content_hash', 64); // SHA-256 of the rendered PDF bytes

            // Storage.
            $table->string('storage_path'); // relative path on 'local' (private) disk
            $table->string('storage_disk', 20)->default('local');

            // Generation metadata.
            $table->string('generated_by'); // employee_id
            $table->timestamp('generated_at');

            // Revocation.
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by')->nullable();
            $table->text('revocation_reason')->nullable();

            // QR verification token (random, non-sensitive).
            $table->string('qr_token', 64)->unique();

            $table->timestamps();

            // Short explicit name: MySQL caps identifiers at 64 chars and the
            // conventional auto-name for these three columns is 84 chars.
            $table->unique(['leave_request_id', 'document_type', 'document_version'], 'oll_req_type_version_unique');
            $table->index('qr_token');
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_leave_letters');
    }
};
