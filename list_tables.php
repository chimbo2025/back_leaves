<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$tables = \DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema='dpp_leave' AND (table_name LIKE '%role%' OR table_name LIKE '%user%')");
foreach ($tables as $t) echo $t->table_name . "\n";
