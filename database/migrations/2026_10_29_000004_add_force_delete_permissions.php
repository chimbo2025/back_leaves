<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletion-policy refactor — two force-delete capabilities.
 *
 *   leave_requests.force_delete  permanently remove a leave request (documents
 *                                and payment cases survive; the audit trail
 *                                records leave_request_force_deleted).
 *   employees.force_delete       hard-delete a staff record and its account
 *                                even when leave requests exist (documents and
 *                                payment cases survive; audit history records
 *                                employee_force_deleted).
 *
 * Granted to the two authority roles named by policy (Final Authority and HR)
 * plus BREAK_GLASS, which mirrors the whole catalog. Idempotent so it is safe
 * on a database already seeded by DatabaseSeeder.
 */
return new class extends Migration
{
    /** @var array<string,array{label:string,description:string}> */
    private array $modules = [
        'leave_requests' => [
            'label' => 'Leave Requests',
            'description' => 'Permanent deletion of leave requests, reserved for Final Authority and HR.',
        ],
        'employees' => [
            'label' => 'Employees',
            'description' => 'Hard deletion of staff records and their accounts.',
        ],
    ];

    /** @var array<string,array{module:string,hint:string}> */
    private array $catalog = [
        'leave_requests.force_delete' => ['module' => 'leave_requests', 'hint' => 'Permanently delete a leave request. Documents and payment cases are preserved; the deletion is audit-logged with a mandatory reason.'],
        'employees.force_delete' => ['module' => 'employees', 'hint' => 'Permanently delete a staff record and its user account, including their leave requests. Documents and payment cases are preserved; audit history is retained.'],
    ];

    /** @var array<string,array<int,string>> role => codes */
    private array $grants = [
        'HR' => ['leave_requests.force_delete', 'employees.force_delete'],
        'FINAL_AUTHORITY' => ['leave_requests.force_delete', 'employees.force_delete'],
        'BREAK_GLASS' => ['leave_requests.force_delete', 'employees.force_delete'],
    ];

    public function up(): void
    {
        // `enforcement_reference` was created as varchar(191) by the original
        // lifecycle migration. The force-delete references name both the route
        // and the policy/controller they resolve to, so they are longer than
        // that. Widen it first; this is additive and keeps existing values.
        if (Schema::hasTable('permissions') && Schema::hasColumn('permissions', 'enforcement_reference')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->string('enforcement_reference', 512)->nullable()->change();
            });
        }

        foreach ($this->modules as $id => $meta) {
            DB::table('modules')->updateOrInsert(
                ['id' => $id],
                ['label' => $meta['label'], 'description' => $meta['description'], 'is_active' => true]
            );
        }

        $permissionIds = [];
        $references = config('permission_enforcement.references', []);

        foreach ($this->catalog as $code => $meta) {
            $action = substr($code, strlen($meta['module']) + 1);
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');

            $lifecycle = [
                'status' => 'implemented',
                'active' => true,
                'enforcement_reference' => $references[$code] ?? null,
            ];

            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId(array_merge([
                    'code' => $code,
                    'module' => $meta['module'],
                    'action' => $action,
                    'label' => $action,
                    'hint' => $meta['hint'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $lifecycle));
            } else {
                // Rows may already exist from a hand-seeded database; make the
                // lifecycle claim explicit rather than trusting the default.
                DB::table('permissions')->where('id', $permissionId)->update($lifecycle);
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
