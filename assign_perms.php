<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Assign missing codes to ADMIN / FINAL_AUTHORITY role if exists
$codes = ['approval_workflows.view','workflows.view','staff.view_own','staff.view_all','employees.view','handovers.view','departments.view','positions.view','divisions.view','units.view','divisions.create','units.create','divisions.update','units.update'];

// Find admin role or final_authority role
$role = \App\Models\Role::where('name','like','%admin%')->orWhere('name','like','%final%')->first();
if ($role) {
    $existing = $role->permissions()->pluck('code')->toArray();
    $toAdd = array_diff($codes, $existing);
    $ids = \DB::table('permissions')->whereIn('code', $toAdd)->pluck('id');
    if ($ids->count()) {
        $role->permissions()->attach($ids);
        echo "Attached to role '{$role->name}' (id {$role->id}): ".implode(',', $toAdd->toArray())."\n";
    }
} else {
    echo "No admin/final role found - assign via UI or find role id\n";
}

// Also assign directly to user u1 (ali.mwinyi@dpp.zn) if they have direct permissions
$user = \App\Models\User::where('email','ali.mwinyi@dpp.zn')->first();
if ($user) {
    $userCodes = $user->permissions()->pluck('code')->toArray();
    $toAttach = array_diff($codes, $userCodes);
    $ids = \DB::table('permissions')->whereIn('code', $toAttach)->pluck('id');
    if ($ids->count()) {
        $user->permissions()->attach($ids);
        echo "Attached directly to user u1 ({$user->email}): ".implode(',', $toAttach)."\n";
    } else {
        echo "User u1 already has all new codes\n";
    }
}
