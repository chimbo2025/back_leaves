<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Clear the permission cache via facades
Illuminate\Support\Facades\Cache::clear('permissions');
echo "Permissions cache cleared.\n";
