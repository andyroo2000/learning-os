<?php

namespace Tests\Unit\FeatureFlags;

use App\Domain\FeatureFlags\Support\FeatureFlagUpdateRateLimiter;
use App\Models\User;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FeatureFlagUpdateRateLimiterTest extends TestCase
{
    public function test_it_limits_updates_per_authenticated_user(): void
    {
        $request = Request::create('/api/feature-flags', 'PATCH');
        $user = new User;
        $user->setAttribute('id', 42);
        $request->setUserResolver(fn (): User => $user);

        $limit = FeatureFlagUpdateRateLimiter::limit($request);

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame('feature-flag-update:user:42', $limit->key);
    }

    #[DataProvider('keyProvider')]
    public function test_it_builds_stable_user_and_network_keys(
        mixed $userId,
        ?string $ip,
        string $expected,
    ): void {
        $this->assertSame($expected, FeatureFlagUpdateRateLimiter::keyFor($userId, $ip));
    }

    /**
     * @return array<string, array{mixed, string|null, string}>
     */
    public static function keyProvider(): array
    {
        return [
            'integer user' => [42, '127.0.0.1', 'feature-flag-update:user:42'],
            'string user' => ['user-1', null, 'feature-flag-update:user:user-1'],
            'anonymous network' => [null, '192.0.2.10', 'feature-flag-update:anon:192.0.2.10'],
            'anonymous unknown network' => [null, null, 'feature-flag-update:anon:unknown-ip'],
        ];
    }
}
