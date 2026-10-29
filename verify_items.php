<?php
require __DIR__ . '/vendor/autoload.php';
function countMatches(string $pat, string $file): int {
    if (!file_exists($file)) return 0;
    $lines = file($file);
    $c = 0;
    foreach ($lines as $ln) {
        if (strpos($ln, $pat) !== false) $c++;
    }
    return $c;
}

$f = 'app/Http/Controllers/Api/OrganizationController.php';
echo "\n";
echo "patterns remaining (expect 0): " . countMatches('->items\(\)->(map|filter|pluck|each|values)', $f) . "\n";
echo "collect(\$divisions->items()) (expect 1): " . countMatches("collect(\$divisions->items())", $f) . "\n";
echo "collect(\$units->items()) (expect 1): " . countMatches("collect(\$units->items())", $f) . "\n";
echo "collect(\$employees->items()) (expect 1): " . countMatches("collect(\$employees->items())", $f) . "\n";
echo "total collect(...items()...) wraps (expect 3): ";
$tot=0; $patterns=['collect(\$divisions->items())','collect(\$units->items())','collect(\$employees->items())'];
foreach($patterns as $p) $tot+=countMatches($p,$f); echo "$tot\n";
