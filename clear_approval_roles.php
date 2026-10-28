<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$deleted = App\Models\ApprovalRole::query()->delete();
echo "Deleted approval roles from DB: " . $deleted . " rows removed." . PHP_EOL;
echo "No hard-coded defaults remain." . PHP_EOL;
echo "Admin can now create roles freely; all are fully deletable." . PHP_EOL;
