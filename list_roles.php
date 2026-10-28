<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
echo "Roles:\n";
$roles = \DB::table('roles')->get();
foreach ($roles as $r) echo $r->id.": ".$r->name."\n";

echo "\nEmployee roles (sample):\n";
$er = \DB::table('employee_roles')->limit(5)->get();
foreach ($er as $e) echo $e->employee_id." -> role ".$e->role_id."\n";

echo "\nUser u1 employee:\n";
$emp = \DB::table('employees')->where('user_id','u1')->first();
if ($emp) echo $emp->id." / ".$emp->first_name." ".$emp->last_name." / zone: ".($emp->zone_id??'')."\n";
