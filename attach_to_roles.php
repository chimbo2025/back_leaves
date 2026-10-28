<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$codes = ['approval_workflows.view','workflows.view','staff.view_own','staff.view_all','employees.view','handovers.view','departments.view','positions.view','divisions.view','units.view','divisions.create','units.create','divisions.update','units.update'];
$ids = \DB::table('permissions')->whereIn('code', $codes)->pluck('id');

$roles = \DB::table('roles')->whereIn('id', ['FINAL_AUTHORITY','HR','DEPT_HEAD','BREAK_GLASS','SUPERVISOR','ZONAL_HEAD','ACCOUNTING'])->pluck('id');

$attached = 0;
foreach ($ids as $pid) {
    foreach ($roles as $rid) {
        $exists = \DB::table('role_permissions')->where('role_id', $rid)->where('permission_id', $pid)->exists();
        if (!$exists) {
            \DB::table('role_permissions')->insert([
                'role_id' => $rid,
                'permission_id' => $pid,
            ]);
            $attached++;
        }
    }
}
echo "Attached $attached role-permission pairs across ".count($roles)." roles.\n";
