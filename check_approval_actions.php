<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
echo "approval_actions columns:\n";
foreach (\DB::select("SELECT column_name, column_type FROM information_schema.columns WHERE table_name='approval_actions' AND table_schema='dpp_leave'") as $col) echo "  ".$col->column_name." (".$col->column_type.")\n";
echo "\nSample approval_actions:\n";
print_r(\DB::table('approval_actions')->limit(5)->get()->pluck('code')->toArray());
echo "\nCount: ".\DB::table('approval_actions')->count()."\n";
