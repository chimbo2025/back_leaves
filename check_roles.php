<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$info = \DB::select("DESCRIBE roles");
foreach ($info as $row) echo $row->Field." (".$row->Type.")\n";
$all = \DB::table('roles')->get();
foreach ($all as $r) echo "id={$r->id}, name={$r->name}, desc={$r->description}\n";
