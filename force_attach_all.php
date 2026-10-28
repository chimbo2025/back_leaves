<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Get all permission IDs for the missing modules
$codes = [
    'approval_workflows'=>'approval_workflows',
    'workflows'=>'workflows',
    'departments'=>'departments',
    'divisions'=>'divisions',
    'units'=>'units',
    'employees'=>'employees',
    'handovers'=>'handovers',
    'positions'=>'positions',
];

$moduleIds = \DB::table('modules')->whereIn('id', array_keys($codes))->pluck('id');
$permissionIds = \DB::table('permissions')->whereIn('module', array_keys($codes))->where('status', 'active')->pluck('id');

echo "Module IDs found: ".count($moduleIds)."\n";
echo "Permission IDs found: ".count($permissionIds)."\n";
print_r($permissionIds->values()->toArray());

// Ensure roles have these
$roleIds = \DB::table('roles')->whereIn('id', ['FINAL_AUTHORITY','HR','DEPT_HEAD','BREAK_GLASS','SUPERVISOR','ZONAL_HEAD','ACCOUNTING'])->pluck('id');
$attached = 0;
foreach ($permissionIds as $pid) {
    foreach ($roleIds as $rid) {
        if (!\DB::table('role_permissions')->where('role_id',$rid)->where('permission_id',$pid)->exists()) {
            \DB::table('role_permissions')->insert(['role_id'=>$rid,'permission_id'=>$pid]);
            $attached++;
        }
    }
}
echo "\nAttached $attached role-permission rows.\n";
