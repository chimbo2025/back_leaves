<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes leave_documents.verify_public — the frontend-only signal that the
 * public QR verification page exists. The page it gated was deleted as dead
 * code during the frontend performance cleanup: no route renders it (app/
 * contains only the leave dashboard, login and profile), so the code had no
 * .ts/.tsx consumer left and became a decorative RBAC-matrix switch whose
 * tick changed nothing. PermissionCatalogIntegrityTest refuses exactly that.
 *
 * Nothing in the API ever consulted this code — the QR verification endpoint
 * is unauthenticated by design — so removing it changes no request outcome.
 * 2026_10_11_120200 granted it only to SELF_SERVICE in the seeder map (not in
 * its own grant list); role_permissions.permission_id is ON DELETE CASCADE,
 * so that grant vanishes with the row. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->where('code', 'leave_documents.verify_public')->delete();
    }

    public function down(): void
    {
        if (DB::table('permissions')->where('code', 'leave_documents.verify_public')->exists()) {
            return;
        }

        DB::table('permissions')->insert([
            'code' => 'leave_documents.verify_public',
            'module' => 'leave_documents',
            'action' => 'verify_public',
            'label' => 'verify_public',
            'hint' => 'Public QR verification endpoint (no auth).',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
