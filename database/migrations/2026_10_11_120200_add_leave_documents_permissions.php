<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 catalog: the leave-documents permission codes and their module.
 *
 * Idempotent, so it is safe on a database already seeded by DatabaseSeeder
 * (which inserts the same codes). The `modules` row is required by
 * PermissionCatalogIntegrityTest: a permission whose module has no row would
 * make the module screen and the RBAC matrix disagree.
 *
 * Role grants are attached here only when the role already exists (migrations
 * run before the seeder on a fresh database). DatabaseSeeder grants the same
 * rows for fresh installs, so neither ordering loses a grant.
 *
 * The capability split is deliberate:
 *   - `generate` is HR-only (approval-side), never self-serve.
 *   - `view_own` / `download_own` for the employee whose leave it is.
 *   - `download_hr` for HR with cross-zone scope.
 *   - `verify_public` is the only capability the unauthenticated QR endpoint needs.
 *   - `revoke` is HR-only.
 */
return new class extends Migration
{
    /** @var array<string,array{label:string,description:string}> */
    private array $modules = [
        'leave_documents' => [
            'label' => 'Leave Documents',
            'description' => 'Official leave letters, digital signatures, and QR verification.',
        ],
    ];

    /** @var array<string,array{module:string,hint:string}> */
    private array $catalog = [
        'leave_documents.generate' => ['module' => 'leave_documents', 'hint' => 'Generate an official leave letter after approval.'],
        'leave_documents.view_own' => ['module' => 'leave_documents', 'hint' => 'View your own official leave letters.'],
        'leave_documents.download_own' => ['module' => 'leave_documents', 'hint' => 'Download your own official leave letters.'],
        'leave_documents.download_hr' => ['module' => 'leave_documents', 'hint' => 'Download official leave letters for staff in your scope (HR).'],
        'leave_documents.verify_public' => ['module' => 'leave_documents', 'hint' => 'Public QR verification endpoint (no auth).'],
        'leave_documents.revoke' => ['module' => 'leave_documents', 'hint' => 'Revoke an official leave letter (HR only).'],
    ];

    /** @var array<string,array<int,string>> role => codes */
    private array $grants = [
        'HR' => ['leave_documents.generate', 'leave_documents.view_own', 'leave_documents.download_own', 'leave_documents.download_hr', 'leave_documents.revoke'],
        'FINAL_AUTHORITY' => ['leave_documents.generate', 'leave_documents.view_own', 'leave_documents.download_own', 'leave_documents.download_hr', 'leave_documents.revoke'],
        'BREAK_GLASS' => ['leave_documents.generate', 'leave_documents.view_own', 'leave_documents.download_own', 'leave_documents.download_hr', 'leave_documents.revoke'],
        'ACCOUNTING' => [], // Accounting does not handle official letters
        'SELF_SERVICE' => ['leave_documents.view_own', 'leave_documents.download_own'],
        'DEPT_HEAD' => ['leave_documents.view_own', 'leave_documents.download_own'],
        'ZONAL_HEAD' => ['leave_documents.view_own', 'leave_documents.download_own'],
        'SUPERVISOR' => ['leave_documents.view_own', 'leave_documents.download_own'],
    ];

    public function up(): void
    {
        foreach ($this->modules as $id => $meta) {
            DB::table('modules')->updateOrInsert(
                ['id' => $id],
                ['label' => $meta['label'], 'description' => $meta['description'], 'is_active' => true]
            );
        }

        $permissionIds = [];

        foreach ($this->catalog as $code => $meta) {
            $action = substr($code, strlen($meta['module']) + 1);

            $permissionId = DB::table('permissions')->where('code', $code)->value('id');

            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'code' => $code,
                    'module' => $meta['module'],
                    'action' => $action,
                    'label' => $action,
                    'hint' => $meta['hint'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $permissionIds[$code] = $permissionId;
        }

        foreach ($this->grants as $roleId => $codes) {
            if (! DB::table('roles')->where('id', $roleId)->exists()) {
                continue;
            }

            foreach ($codes as $code) {
                $exists = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionIds[$code])
                    ->exists();

                if (! $exists) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionIds[$code],
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', array_keys($this->catalog))->pluck('id')->all();

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('modules')->whereIn('id', array_keys($this->modules))->delete();
    }
};
