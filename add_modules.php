<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$missing = [
    ['id'=>'approval_workflows','name'=>'Approval Workflows'],
    ['id'=>'workflows','name'=>'Workflows'],
    ['id'=>'departments','name'=>'Departments'],
    ['id'=>'divisions','name'=>'Divisions'],
    ['id'=>'units','name'=>'Units'],
    ['id'=>'employees','name'=>'Employees'],
    ['id'=>'handovers','name'=>'Handovers'],
    ['id'=>'positions','name'=>'Positions'],
    ['id'=>'staff','name'=>'Staff'],
];
$added = 0;
foreach ($missing as $m) {
    if (!\DB::table('modules')->where('id', $m['id'])->exists()) {
        \DB::table('modules')->insert(['id'=>$m['id'],'name'=>$m['name'],'created_at'=>now(),'updated_at'=>now()]);
        echo "ADDED module: {$m['id']}\n"; $added++;
    } else { echo "EXISTS: {$m['id']}\n"; }
}
echo "Total modules added: $added\n";
