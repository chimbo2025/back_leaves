<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$missing = [
    ['code'=>'approval_workflows.view','name'=>'Approval Workflows View','module'=>'approval_workflows','action'=>'view'],
    ['code'=>'workflows.view','name'=>'Workflows View','module'=>'workflows','action'=>'view'],
    ['code'=>'staff.view_own','name'=>'Staff Own View','module'=>'staff','action'=>'view_own'],
    ['code'=>'staff.view_all','name'=>'Staff View All','module'=>'staff','action'=>'view_all'],
    ['code'=>'employees.view','name'=>'Employees View','module'=>'employees','action'=>'view'],
    ['code'=>'handovers.view','name'=>'Handovers View','module'=>'handovers','action'=>'view'],
    ['code'=>'departments.view','name'=>'Departments View','module'=>'departments','action'=>'view'],
    ['code'=>'positions.view','name'=>'Positions View','module'=>'positions','action'=>'view'],
    ['code'=>'divisions.view','name'=>'Divisions View','module'=>'divisions','action'=>'view'],
    ['code'=>'units.view','name'=>'Units View','module'=>'units','action'=>'view'],
    ['code'=>'divisions.create','name'=>'Divisions Create','module'=>'divisions','action'=>'create'],
    ['code'=>'units.create','name'=>'Units Create','module'=>'units','action'=>'create'],
    ['code'=>'divisions.update','name'=>'Divisions Update','module'=>'divisions','action'=>'update'],
    ['code'=>'units.update','name'=>'Units Update','module'=>'units','action'=>'update'],
];

$added = 0;
foreach ($missing as $m) {
    $exists = \DB::table('permissions')->where('code', $m['code'])->exists();
    if (!$exists) {
        \DB::table('permissions')->insert([
            'code' => $m['code'],
            'name' => $m['name'],
            'module' => $m['module'],
            'action' => $m['action'],
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
