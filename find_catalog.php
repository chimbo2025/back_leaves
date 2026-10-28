<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$tables = \DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema='dpp_leave'");
foreach ($tables as $t) {
    $name = strtolower($t->table_name);
    if (strpos($name, 'module') !== false || strpos($name, 'catalog') !== false || strpos($name, 'action') !== false) echo $t->table_name . "\n";
}
