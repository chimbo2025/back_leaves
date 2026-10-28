<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$codes = ['divisions.create','divisions.update','divisions.view','units.create','units.update','units.view'];
$pids = DB::table('permissions')->whereIn('code', $codes)->pluck('id');
$roles = DB::table('roles')->pluck('id');
$attached = 0;
foreach ($pids as $pid) {
    foreach ($roles as $rid) {
        $exists = DB::table('role_permissions')->where('role_id', $rid)->where('permission_id', $pid)->exists();
        if (!$exists) {
            DB::table('role_permissions')->insert(['role_id'=>$rid,'permission_id'=>$pid]);
            $attached++;
        }
    }
}
echo "Attached $attached role-permission pairs for divisions/units.\n";
