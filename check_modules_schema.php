<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$info = \DB::select("DESCRIBE modules");
foreach ($info as $row) echo $row->Field." (".$row->Type.") => ".\DB::select("SELECT id FROM modules WHERE id='admin'")[0]->id."\n";
