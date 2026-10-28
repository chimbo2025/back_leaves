<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 catalog: the leave-payment permission codes and their module.
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
 * The capability split is deliberate and is the whole point of the phase:
 *   - `review` and `approve` are separate codes, so "may look at it" never
 *     implies "may authorise it".
 *   - `receive` and `process` are Accounting codes, held by no HR role, so the
 *     office that approves a payment cannot also be the office that pays it.
 *   - `view_cross_zone` is the one addition beyond the specification's list: the
 *     documented AccessScopeService invariant is that organisation-wide reach is
 *     granted ONLY by an explicit `<module>.view_cross_zone` code, so Accounting
 *     (which must see every approved case) needs it.
 *
 * A dedicated ACCOUNTING role is seeded because the capability has to have an
 * owner: a code that no active role holds is dead weight and fails the catalog
 * integrity suite. See DatabaseSeeder.
 */
return new class extends Migration
{
    /** @var array<string,array{label:string,description:string}> */
    private array $modules = [
        'leave_payments' => [
            'label' => 'Leave Payments',
            'description' => 'Leave encashment and leave-purchase payment cases: eligibility, HR review and Accounting settlement.',
        ],
    ];

    /** @var array<string,array{module:string,hint:string}> */
    private array $catalog = [
        'leave_payments.view_own' => ['module' => 'leave_payments', 'hint' => 'View your own leave payment cases.'],
        'leave_payments.view_department' => ['module' => 'leave_payments', 'hint' => 'View leave payment cases for staff in departments you head.'],
        'leave_payments.view_zone' => ['module' => 'leave_payments', 'hint' => 'View leave payment cases for staff in zones you head.'],
        'leave_payments.view_cross_zone' => ['module' => 'leave_payments', 'hint' => 'View leave payment cases across ALL departments and zones. Central offices only; regional roles stay zone-scoped.'],
        'leave_payments.create' => ['module' => 'leave_payments', 'hint' => 'Open a leave payment case and evaluate its eligibility.'],
        'leave_payments.review' => ['module' => 'leave_payments', 'hint' => 'Send an eligible payment case for HR review / onward to Accounting.'],
        'leave_payments.approve' => ['module' => 'leave_payments', 'hint' => 'Approve a leave payment case. Separate from review and never self-approvable.'],
        'leave_payments.reject' => ['module' => 'leave_payments', 'hint' => 'Reject a leave payment case during HR review.'],
        'leave_payments.receive' => ['module' => 'leave_payments', 'hint' => 'Accounting: acknowledge receipt of a sent leave payment case.'],
        'leave_payments.process' => ['module' => 'leave_payments', 'hint' => 'Accounting: settle a leave payment case as paid, or reject the payment.'],
        'leave_payments.cancel' => ['module' => 'leave_payments', 'hint' => 'Cancel a leave payment case before it is paid.'],
    ];

    /** @var array<string,array<int,string>> role => codes */
    private array $grants = [
        'SELF_SERVICE' => ['leave_payments.view_own'],
        'DEPT_HEAD' => ['leave_payments.view_department'],
        'ZONAL_HEAD' => ['leave_payments.view_zone'],
        'HR' => ['leave_payments.view_own', 'leave_payments.view_department', 'leave_payments.view_zone', 'leave_payments.view_cross_zone', 'leave_payments.create', 'leave_payments.review', 'leave_payments.approve', 'leave_payments.reject', 'leave_payments.cancel'],
        'ACCOUNTING' => ['leave_payments.view_cross_zone', 'leave_payments.receive', 'leave_payments.process'],
        'FINAL_AUTHORITY' => ['leave_payments.view_own', 'leave_payments.view_department', 'leave_payments.view_zone', 'leave_payments.view_cross_zone', 'leave_payments.create', 'leave_payments.review', 'leave_payments.approve', 'leave_payments.reject', 'leave_payments.receive', 'leave_payments.process', 'leave_payments.cancel'],
        'BREAK_GLASS' => ['leave_payments.view_own', 'leave_payments.view_department', 'leave_payments.view_zone', 'leave_payments.view_cross_zone', 'leave_payments.create', 'leave_payments.review', 'leave_payments.approve', 'leave_payments.reject', 'leave_payments.receive', 'leave_payments.process', 'leave_payments.cancel'],
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
