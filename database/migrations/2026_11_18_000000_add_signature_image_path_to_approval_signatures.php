<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stored approval-signature image (drawn/uploaded during the decision
 * ceremony). Previously the frontend attached the image to the decision as
 * `signature_path`, but the API never accepted it — the drawn mark was
 * discarded at the HTTP boundary, so the official letter could only ever
 * print a blank signature rule.
 *
 * The image file lives under storage/app/signatures/{request_id}/ and this
 * column holds its storage-relative path. `signature_value` keeps the
 * ceremony hash untouched; this column is additive and nullable, so
 * pre-existing signature rows and HSM-reference seals are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('approval_signatures') && ! Schema::hasColumn('approval_signatures', 'signature_image_path')) {
            Schema::table('approval_signatures', function (Blueprint $table) {
                $table->string('signature_image_path', 255)->nullable()->after('signature_value');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('approval_signatures') && Schema::hasColumn('approval_signatures', 'signature_image_path')) {
            Schema::table('approval_signatures', function (Blueprint $table) {
                $table->dropColumn('signature_image_path');
            });
        }
    }
};
