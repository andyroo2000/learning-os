<?php

// Operator-only fixture provisioning: run from the application's root over SSH.
// Capture stdout privately; it contains credentials for this disposable account.
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @return array{token: string, password: string, email: string} */
function provisionCliFixture(): array
{
    $password = bin2hex(random_bytes(32));
    $email = 'cli-integration-'.strtolower((string) Str::ulid()).'@example.test';
    $user = User::create([
        'name' => 'Disposable CLI integration fixture',
        'email' => $email,
        'password' => $password,
    ]);
    $user->forceFill(['email_verified_at' => now()])->save();

    return [
        'token' => $user->createToken('cli-integration', ['*'], now()->addHour())->plainTextToken,
        'password' => $password,
        'email' => $email,
    ];
}

echo json_encode(provisionCliFixture(), JSON_THROW_ON_ERROR);
