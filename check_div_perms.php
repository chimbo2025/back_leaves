<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$codes = ['divisions.create','divisions.update','divisions.view','units.create','units.update','units.view'];
$ids = DB::table('permissions')->whereIn('code', $codes)->pluck('id');
$codes_found = DB::table('permissions')->whereIn('code', $codes)->pluck('code');
echo "Found permissions: " . implode(',', $codes_found->toArray()) . "\n";

// Check which roles have them
$roles = DB::table('roles')->pluck('id');
foreach ($ids as $pid) {
    $attached = DB::table('role_permissions')->where('permission_id', $pid)->pluck('role_id')->toArray();
    echo "Permission id $pid attached to roles: " . implode(',', $attached) . "\n";
}
