<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Check approval_actions
$stmt = DB::select('SHOW CREATE TABLE approval_actions');
if ($stmt) {
    echo "approval_actions exists\n";
    echo $stmt[0]->{'Create Table'} . "\n";
} else {
    echo "approval_actions MISSING\n";
}

// Check users
$stmt2 = DB::select("SHOW TABLES LIKE 'users'");
if (count($stmt2) > 0) {
    echo "users exists\n";
} else {
    echo "users MISSING\n";
}

// List all tables with missing PK
$tables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()");
foreach ($tables as $t) {
    $name = $t->table_name ?? $t->TABLE_NAME;
    $pk = DB::select("SELECT constraint_name FROM information_schema.key_column_usage WHERE table_name = ? AND constraint_name = 'PRIMARY'", [$name]);
    echo ($name . ': ' . (count($pk) ? 'PK FOUND' : 'PK MISSING') . "\n");
}
