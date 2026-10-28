<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$codes = \DB::table('permissions')->pluck('code');
echo "Total permission codes in DB: " . $codes->count() . "\n";
echo "Sample codes:\n";
print_r($codes->take(30)->values()->toArray());

echo "\n--- Checking required ones ---\n";
$required = ['policies.view','approval_workflows.view','staff.view_own','staff.view_all','handovers.view','workflows.view','employees.view','requests.view','departments.view','positions.view','admin.view','divisions.view','units.view','reports.view'];
foreach ($required as $r) {
    $found = $codes->contains($r);
    echo ($found ? "FOUND " : "MISSING ") . $r . "\n";
}
