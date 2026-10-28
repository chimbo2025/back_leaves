<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
echo "modules columns:\n";
foreach (\DB::select("SELECT column_name, column_type FROM information_schema.columns WHERE table_name='modules' AND table_schema='dpp_leave'") as $col) {
    echo "  " . $col->column_name . " (" . $col->column_type . ")\n";
}
echo "\nExisting modules:\n";
print_r(\DB::table('modules')->get()->pluck('id', 'id')->toArray());
