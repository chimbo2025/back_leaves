<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$users = [
    'u1' => 'ali.mwinyi@dpp.zn',
    'u2' => 'salma.juma@dpp.zn',
    'u3' => 'fatma.hassan@dpp.zn',
    'u4' => 'rashid.haji@dpp.zn',
    'u5' => 'juma.mohammed@dpp.zn',
    'u6' => 'zaytuni.omar@dpp.zn',
    'u7' => 'asha.hassan@dpp.zn',
    'u8' => 'mohammed.salim@dpp.zn',
    'u9' => 'rehema.mzuri@dpp.zn',
    'u10' => 'baraka.maulid@dpp.zn',
    'u11' => 'khadija.omar@dpp.zn',
    'u12' => 'haji.abdullah@dpp.zn',
];

$updated = 0;
foreach ($users as $uid => $email) {
    $user = App\Models\User::where('id', $uid)->first();
    if ($user && ! $user->email) {
        $user->email = $email;
        $user->save();
        echo "Updated $uid -> $email" . PHP_EOL;
        $updated++;
    } else {
        echo "Skipped $uid (already has email or not found)" . PHP_EOL;
    }
}
echo PHP_EOL . "Updated $updated users with email." . PHP_EOL;
