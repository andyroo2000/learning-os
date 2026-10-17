<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @return array{token: string, otherToken: string} */
function seedCliFixture(Application $app): array
{
    if (! $app->environment('testing') || config('database.default') !== 'sqlite') {
        throw new RuntimeException('CLI fixture seeding requires an isolated testing SQLite database.');
    }

    $user = User::factory()->create(['email' => 'cli-fixture@example.test']);
    $other = User::factory()->create(['email' => 'cli-other@example.test']);

    // stdout is captured into private temporary profiles by the local runner.
    return [
        'token' => $user->createToken('cli-integration')->plainTextToken,
        'otherToken' => $other->createToken('cli-integration')->plainTextToken,
    ];
}

echo json_encode(seedCliFixture($app), JSON_THROW_ON_ERROR);
