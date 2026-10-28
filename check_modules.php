<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
echo "=== modules ===\n";
$mods = \DB::table('modules')->get();
foreach ($mods as $m) echo $m->id.': '.($m->name??$m->code??'').' (code:'.($m->code??'').') actions: '.($m->actions??'')."\n";

echo "\n=== approval_actions ===\n";
$acts = \DB::table('approval_actions')->get();
foreach ($acts as $a) echo $a->id.': '.($a->code??'')." / ".$a->label." (module: ".$a->module_id.")\n";
