<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$missing = [
    ['code'=>'approval_workflows.view','label'=>'Approval Workflows View','module'=>'approval_workflows','action'=>'view','status'=>'active','active'=>1],
    ['code'=>'workflows.view','label'=>'Workflows View','module'=>'workflows','action'=>'view','status'=>'active','active'=>1],
    ['code'=>'staff.view_own','label'=>'Staff Own View','module'=>'staff','action'=>'view_own','status'=>'active','active'=>1],
    ['code'=>'staff.view_all','label'=>'Staff View All','module'=>'staff','action'=>'view_all','status'=>'active','active'=>1],
    ['code'=>'employees.view','label'=>'Employees View','module'=>'employees','action'=>'view','status'=>'active','active'=>1],
    ['code'=>'handovers.view','label'=>'Handovers View','module'=>'handovers','action'=>'view','status'=>'active','active'=>1],
    ['code'=>'departments.view','label'=>'Departments View','module'=>'departments','action'=>'view','status'=>'active','active'=>1],
    ['code'=>'positions.view','label'=>'Positions View','module'=>'positions','action'=>'view','status'=>'active','active'=>1],
    ['code'=>'divisions.view','label'=>'Divisions View','module'=>'divisions','action'=>'view','status'=>'active','active'=>1],
    ['code'=>'units.view','label'=>'Units View','module'=>'units','action'=>'view','status'=>'active','active'=>1],
    ['code'=>'divisions.create','label'=>'Divisions Create','module'=>'divisions','action'=>'create','status'=>'active','active'=>1],
    ['code'=>'units.create','label'=>'Units Create','module'=>'units','action'=>'create','status'=>'active','active'=>1],
    ['code'=>'divisions.update','label'=>'Divisions Update','module'=>'divisions','action'=>'update','status'=>'active','active'=>1],
    ['code'=>'units.update','label'=>'Units Update','module'=>'units','action'=>'update','status'=>'active','active'=>1],
];

$added = 0;
foreach ($missing as $m) {
    $exists = \DB::table('permissions')->where('code', $m['code'])->exists();
    if (!$exists) {
        \DB::table('permissions')->insert([
            'code' => $m['code'],
            'label' => $m['label'],
            'module' => $m['module'],
            'action' => $m['action'],
            'status' => $m['status'],
            'active' => $m['active'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        echo "ADDED: {$m['code']}\n";
        $added++;
    } else {
        echo "EXISTS: {$m['code']}\n";
    }
}
echo "\nTotal added: $added\n";
