<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StudyCompatibilityTrafficRateLimiterTest extends TestCase
{
    public function test_network_limit_uses_a_network_only_300_per_minute_bucket(): void
    {
        $request = $this->request('192.0.2.10', 42);

        $limit = StudyCompatibilityTrafficRateLimiter::networkLimit($request);

        $this->assertSame(300, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('study-compatibility-network:network:192.0.2.10', $limit->key);
    }

    public function test_read_and_media_limits_use_separate_actor_scoped_buckets(): void
    {
        $request = $this->request('192.0.2.10', 42);

        $read = StudyCompatibilityTrafficRateLimiter::readLimit($request);
        $media = StudyCompatibilityTrafficRateLimiter::mediaLimit($request);

        $this->assertSame(240, $read->maxAttempts);
        $this->assertSame('study-compatibility-read:user:42', $read->key);
        $this->assertSame(600, $media->maxAttempts);
        $this->assertSame('study-compatibility-media:user:42', $media->key);
    }

    #[DataProvider('fallbackIdentityProvider')]
    public function test_keys_have_stable_network_fallbacks(
        mixed $userId,
        ?string $ip,
        string $expectedActor,
        string $expectedNetwork,
    ): void {
        $this->assertSame(
            'study-compatibility-read:'.$expectedActor,
            StudyCompatibilityTrafficRateLimiter::actorKey(
                StudyCompatibilityTrafficRateLimiter::READ_NAME,
                $userId,
                $ip,
            ),
        );
        $this->assertSame(
            'study-compatibility-network:network:'.$expectedNetwork,
            StudyCompatibilityTrafficRateLimiter::networkKey($ip),
        );
    }

    public static function fallbackIdentityProvider(): array
    {
        return [
            'missing identity and address' => [null, null, 'anon:unknown-ip', 'unknown-ip'],
            'blank identity and address' => ['', '', 'anon:unknown-ip', 'unknown-ip'],
            'anonymous address' => [null, '192.0.2.10', 'anon:192.0.2.10', '192.0.2.10'],
            'numeric user' => [42, '192.0.2.10', 'user:42', '192.0.2.10'],
            'string user' => ['user-1', null, 'user:user-1', 'unknown-ip'],
        ];
    }

    private function request(?string $ip, mixed $userId): Request
    {
        $server = $ip === null ? [] : ['REMOTE_ADDR' => $ip];
        $request = Request::create('/api/study/overview', 'GET', [], [], [], $server);
        $request->setUserResolver(fn () => $userId === null ? null : new class($userId)
        {
            public function __construct(private readonly mixed $id) {}

            public function getAuthIdentifier(): mixed
            {
                return $this->id;
            }
        });

        return $request;
    }
}
