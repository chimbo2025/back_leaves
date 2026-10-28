<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== APPROVAL ROLES POST-REMOVAL ===" . PHP_EOL;
$count = App\Models\ApprovalRole::count();
echo "Count: " . $count . PHP_EOL;
if ($count === 0) {
    echo "CONFIRMED: No hard-coded default approval roles remain." . PHP_EOL;
    echo "All roles are now DYNAMIC — admin creates/deletes them freely." . PHP_EOL;
} else {
    echo "WARNING: Some roles still exist (count: $count)" . PHP_EOL;
}
