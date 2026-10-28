<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== USERS IN DATABASE ===" . PHP_EOL . str_repeat("=", 50) . PHP_EOL;

$users = App\Models\User::all();
if ($users->isEmpty()) {
    echo "No users found." . PHP_EOL;
} else {
    foreach ($users as $u) {
        $emp = $u->employee;
        echo "  ID: {$u->id} | username: {$u->username} | email: {$u->email} | active: " . ($u->is_active ? 'yes' : 'no') . PHP_EOL;
        echo "  Employee: " . ($emp ? "{$emp->full_name} ({$emp->id})" : 'N/A') . PHP_EOL;
        echo PHP_EOL;
    }
}
echo "Total users: " . $users->count() . PHP_EOL;