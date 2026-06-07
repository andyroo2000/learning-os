<?php

namespace Tests\Unit\Media;

use App\Domain\Media\Support\CardMediaRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class CardMediaRateLimiterTest extends TestCase
{
    public function test_it_builds_stable_operation_scoped_keys(): void
    {
        $this->assertSame('card-media-attach:user:42', CardMediaRateLimiter::keyFor(CardMediaRateLimiter::ATTACH_NAME, 42, '127.0.0.1'));
        $this->assertSame('card-media-attach:user:42', CardMediaRateLimiter::keyFor(CardMediaRateLimiter::ATTACH_NAME, 42, '192.0.2.10'));
        $this->assertSame('card-media-attach:anon:unknown-ip', CardMediaRateLimiter::keyFor(CardMediaRateLimiter::ATTACH_NAME, null, null));
        $this->assertSame('card-media-attach:anon:unknown-ip', CardMediaRateLimiter::keyFor(CardMediaRateLimiter::ATTACH_NAME, null, ''));
        $this->assertSame('card-media-attach:anon:127.0.0.1', CardMediaRateLimiter::keyFor(CardMediaRateLimiter::ATTACH_NAME, null, '127.0.0.1'));
        $this->assertSame('card-media-attach:user:0', CardMediaRateLimiter::keyFor(CardMediaRateLimiter::ATTACH_NAME, 0, '127.0.0.1'));
        $this->assertSame('card-media-attach:anon:127.0.0.1', CardMediaRateLimiter::keyFor(CardMediaRateLimiter::ATTACH_NAME, false, '127.0.0.1'));
        $this->assertSame('card-media-attach:user:user-1', CardMediaRateLimiter::keyFor(CardMediaRateLimiter::ATTACH_NAME, 'user-1', ''));
        $this->assertSame('card-media-detach:user:42', CardMediaRateLimiter::keyFor(CardMediaRateLimiter::DETACH_NAME, 42, '127.0.0.1'));
        $this->assertSame('card-media-detach:anon:127.0.0.1', CardMediaRateLimiter::keyFor(CardMediaRateLimiter::DETACH_NAME, null, '127.0.0.1'));
    }

    public function test_attach_uses_60_attempts_per_minute_by_default(): void
    {
        $limit = CardMediaRateLimiter::forAttach()->limit($this->requestWithUserId(42));

        $this->assertSame(60, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('card-media-attach:user:42', $limit->key);
    }

    public function test_detach_uses_60_attempts_per_minute_by_default(): void
    {
        $limit = CardMediaRateLimiter::forDetach()->limit($this->requestWithUserId(42));

        $this->assertSame(60, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('card-media-detach:user:42', $limit->key);
    }

    private function requestWithUserId(int $userId): Request
    {
        $request = Request::create('/api/cards/01HWZ1KCE7000000000000000/media-assets', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->setUserResolver(fn () => new class($userId)
        {
            public function __construct(private readonly int $userId) {}

            public function getAuthIdentifier(): int
            {
                return $this->userId;
            }
        });

        return $request;
    }
}
