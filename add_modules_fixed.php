<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$missing = [
    ['id'=>'approval_workflows','label'=>'Approval Workflows','desc'=>'Approval workflow management'],
    ['id'=>'workflows','label'=>'Workflows','desc'=>'Workflow engine'],
    ['id'=>'departments','label'=>'Departments','desc'=>'Department management'],
    ['id'=>'divisions','label'=>'Divisions','desc'=>'Division management'],
    ['id'=>'units','label'=>'Units','desc'=>'Independent unit management'],
    ['id'=>'employees','label'=>'Employees','desc'=>'Employee management'],
    ['id'=>'handovers','label'=>'Handovers','desc'=>'Handover management'],
    ['id'=>'positions','label'=>'Positions','desc'=>'Position management'],
];
foreach ($missing as $m) {
    if (!\DB::table('modules')->where('id', $m['id'])->exists()) {
        \DB::table('modules')->insert([
            'id' => $m['id'],
            'label' => $m['label'],
            'description' => $m['desc'],
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        echo "ADDED module: {$m['id']}\n";
    } else {
        echo "EXISTS: {$m['id']}\n";
    }
}
