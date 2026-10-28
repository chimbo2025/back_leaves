<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$info = \DB::select("DESCRIBE permissions");
echo "Columns in permissions table:\n";
foreach ($info as $row) { echo $row->Field . " (" . $row->Type . ")\n"; }
