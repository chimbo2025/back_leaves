<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 catalog: the notification permission codes and their module.
 *
 * Idempotent, like the Phase 2/3 catalog migrations, so it is safe on a
 * database already seeded by DatabaseSeeder (which inserts the same codes).
 *
 * Three codes, and deliberately not `notifications.view`:
 *   - notifications.view_own          read/mark your own notifications
 *   - notifications.manage_preferences choose your own channel + timing
 *   - notifications.view_sensitive     receive leave detail (type/reason) that
 *                                      is withheld from ordinary recipients
 *
 * `view_sensitive` is the one addition beyond the specification's list, and it
 * is what lets a late-return or medical-leave message carry detail to HR and
 * the authority roles while an ordinary line manager still receives only the
 * privacy-safe shell. Without an explicit code the "managers must not see
 * medical detail" rule would have to be hard-coded to role names.
 */
return new class extends Migration
{
    /** @var array<string,array{label:string,description:string}> */
    private array $modules = [
        'notifications' => [
            'label' => 'Notifications',
            'description' => 'Read your own notifications, manage delivery preferences, and receive sensitive leave detail.',
        ],
    ];

    /** @var array<string,array{module:string,hint:string}> */
    private array $catalog = [
        'notifications.view_own' => ['module' => 'notifications', 'hint' => 'Read and mark your own notifications.'],
        'notifications.manage_preferences' => ['module' => 'notifications', 'hint' => 'Choose which optional notifications you receive and on which channel.'],
        'notifications.view_sensitive' => ['module' => 'notifications', 'hint' => 'Receive leave detail (type and reason) that is withheld from ordinary recipients.'],
    ];

    /** @var array<string,array<int,string>> role => codes */
    private array $grants = [
        'SELF_SERVICE' => ['notifications.view_own', 'notifications.manage_preferences'],
        'HR' => ['notifications.view_own', 'notifications.manage_preferences', 'notifications.view_sensitive'],
        'FINAL_AUTHORITY' => ['notifications.view_own', 'notifications.manage_preferences', 'notifications.view_sensitive'],
        'BREAK_GLASS' => ['notifications.view_own', 'notifications.manage_preferences', 'notifications.view_sensitive'],
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
