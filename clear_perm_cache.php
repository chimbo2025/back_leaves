<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Clear permission caches for all users with 'perms:' prefix
Cache::flush(); // simplest: clear all cache

echo "Cache cleared.\n";
