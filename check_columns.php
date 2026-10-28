<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Users columns: " . implode(", ", \Illuminate\Support\Facades\Schema::getColumnListing('users')) . PHP_EOL;
echo "Employees columns: " . implode(", ", \Illuminate\Support\Facades\Schema::getColumnListing('employees')) . PHP_EOL;