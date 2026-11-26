<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/*
 * permissions:revoke-cross-zone is a read-only report unless --apply is given,
 * and --apply must leave the roles on the keep list untouched.
 */

function crossZoneHolders(): array
{
    $id = Permission::where('code', 'requests.view_cross_zone')->value('id');

    return DB::table('role_permissions')->where('permission_id', $id)->pluck('role_id')->sort()->values()->all();
}

beforeEach(function () {
    $permissionId = Permission::where('code', 'requests.view_cross_zone')->value('id');

    foreach (['TEST_DIRECTOR', 'TEST_PROSECUTOR'] as $roleId) {
        Role::create(['id' => $roleId, 'name' => $roleId, 'approval_tier' => 3, 'is_final_authority' => false, 'is_system' => false, 'active' => true]);
        DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
    }
});

it('reports without changing anything by default', function () {
    $before = crossZoneHolders();

    $this->artisan('permissions:revoke-cross-zone')
        ->expectsOutputToContain('TEST_DIRECTOR')
        ->expectsOutputToContain('Read-only report')
        ->assertSuccessful();

    expect(crossZoneHolders())->toBe($before);
});

it('removes the code only from roles outside the keep list when --apply is given', function () {
    expect(crossZoneHolders())->toContain('HR', 'FINAL_AUTHORITY', 'BREAK_GLASS', 'TEST_DIRECTOR', 'TEST_PROSECUTOR');

    $this->artisan('permissions:revoke-cross-zone', ['--apply' => true])
        ->expectsOutputToContain('To undo, run this SQL')
        ->assertSuccessful();

    expect(crossZoneHolders())->toBe(['BREAK_GLASS', 'FINAL_AUTHORITY', 'HR']);

    // One audit entry per revoked role.
    expect(DB::table('audit_logs')->where('action', 'permission_revoke')->count())->toBe(2);
});

it('honours a custom keep list', function () {
    $this->artisan('permissions:revoke-cross-zone', ['--apply' => true, '--keep' => 'HR,TEST_DIRECTOR'])->assertSuccessful();

    expect(crossZoneHolders())->toBe(['HR', 'TEST_DIRECTOR']);
});
