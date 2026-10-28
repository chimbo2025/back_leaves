<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$codes = ['approval_workflows.view','workflows.view','staff.view_own','staff.view_all','employees.view','handovers.view','departments.view','positions.view','divisions.view','units.view','divisions.create','units.create','divisions.update','units.update'];
$ids = \DB::table('permissions')->whereIn('code', $codes)->pluck('id');

echo "Permission IDs to attach: ".implode(',', $ids->values()->toArray())."\n";

$user = \App\Models\User::where('email','ali.mwinyi@dpp.zn')->first();
if ($user) {
    $existing = $user->permissions()->pluck('id')->toArray();
    $newIds = array_diff($ids->values()->toArray(), $existing);
    if (count($newIds) > 0) {
        $user->permissions()->attach($newIds);
        echo "Assigned ".count($newIds)." codes to user u1 (ali.mwinyi@dpp.zn)\n";
    } else {
        echo "User u1 already has all codes\n";
    }
} else {
    echo "User u1 not found\n";
}
