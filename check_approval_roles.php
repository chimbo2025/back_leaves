<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== APPROVAL ROLES — Default Catalog ===" . PHP_EOL . str_repeat("=", 55) . PHP_EOL;

// Show all roles
$roles = App\Models\ApprovalRole::all();

foreach ($roles as $r) {
    $line = sprintf(
        "%-20s | type=%-22s | protected=%-3s | active=%-3s",
        $r->code,
        $r->resolution_type,
        ($r->is_system_protected ? 'YES' : 'no'),
        ($r->is_active ? 'YES' : 'no')
    );
    echo $line . PHP_EOL;
}

echo PHP_EOL . "=== ANALYSIS ===" . PHP_EOL;
echo "Total roles: " . $roles->count() . PHP_EOL;

$system = $roles->where('is_system_protected', true);
echo "System-protected (locked): " . $system->count() . " (";
echo implode(", ", $system->pluck('code')->all());
echo ")" . PHP_EOL;

$nonSystem = $roles->where('is_system_protected', false);
echo "Configurable/admin-managed: " . $nonSystem->count() . " (";
echo implode(", ", $nonSystem->pluck('code')->all());
echo ")" . PHP_EOL;

echo PHP_EOL . "=== RESOLUTION TYPE BREAKDOWN ===" . PHP_EOL;
$types = $roles->groupBy('resolution_type');
foreach ($types as $type => $items) {
    echo "  {$type}: " . implode(", ", $items->pluck('code')->all()) . PHP_EOL;
}

echo PHP_EOL . "=== DEFAULT OR DYNAMIC? ===" . PHP_EOL;
echo "The approval_roles table holds a DEFAULT CATALOG (seeded)." . PHP_EOL;
echo "  - 'Default' = pre-seeded: 10 core roles that match the DPP hierarchy." . PHP_EOL;
echo "  - 'Dynamic' = admin can ADD new roles per organization needs, but" . PHP_EOL;
echo "     must never delete the 2 system-protected roles (final_authority, deputy_dpp)." . PHP_EOL;
echo "  - Workflow steps reference them via approval_role_id (foreign key)." . PHP_EOL;
echo "  - Per-employee roles are stored in employee_approval_roles table." . PHP_EOL;
