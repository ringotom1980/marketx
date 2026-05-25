<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$email = $argv[1] ?? null;
$password = $argv[2] ?? null;
$name = $argv[3] ?? '管理者';

if (! $email || ! $password) {
    fwrite(STDERR, "Usage: php scripts/ensure_admin_user.php <email> <password> [name]\n");
    exit(1);
}

$user = User::query()->updateOrCreate(
    ['email' => strtolower($email)],
    [
        'name' => $name,
        'password' => $password,
        'is_admin' => true,
    ]
);

echo 'admin_user_id='.$user->id.PHP_EOL;
