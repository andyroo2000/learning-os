<?php

namespace Tests\Unit\Media;

use App\Domain\Media\Support\MediaAssetRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class MediaAssetRateLimiterTest extends TestCase
{
    public function test_it_builds_stable_operation_scoped_keys(): void
    {
        $this->assertSame('media-asset-create:user:42', MediaAssetRateLimiter::keyFor(MediaAssetRateLimiter::CREATE_NAME, 42, '127.0.0.1'));
        $this->assertSame('media-asset-create:user:42', MediaAssetRateLimiter::keyFor(MediaAssetRateLimiter::CREATE_NAME, 42, '192.0.2.10'));
        $this->assertSame('media-asset-create:anon:unknown-ip', MediaAssetRateLimiter::keyFor(MediaAssetRateLimiter::CREATE_NAME, null, null));
        $this->assertSame('media-asset-create:anon:unknown-ip', MediaAssetRateLimiter::keyFor(MediaAssetRateLimiter::CREATE_NAME, null, ''));
        $this->assertSame('media-asset-create:anon:127.0.0.1', MediaAssetRateLimiter::keyFor(MediaAssetRateLimiter::CREATE_NAME, '', '127.0.0.1'));
        $this->assertSame('media-asset-create:anon:127.0.0.1', MediaAssetRateLimiter::keyFor(MediaAssetRateLimiter::CREATE_NAME, null, '127.0.0.1'));
        $this->assertSame('media-asset-create:user:0', MediaAssetRateLimiter::keyFor(MediaAssetRateLimiter::CREATE_NAME, 0, '127.0.0.1'));
        $this->assertSame('media-asset-create:anon:127.0.0.1', MediaAssetRateLimiter::keyFor(MediaAssetRateLimiter::CREATE_NAME, false, '127.0.0.1'));
        $this->assertSame('media-asset-create:user:user-1', MediaAssetRateLimiter::keyFor(MediaAssetRateLimiter::CREATE_NAME, 'user-1', ''));
        $this->assertSame('media-asset-delete:user:42', MediaAssetRateLimiter::keyFor(MediaAssetRateLimiter::DELETE_NAME, 42, '127.0.0.1'));
        $this->assertSame('media-asset-delete:anon:127.0.0.1', MediaAssetRateLimiter::keyFor(MediaAssetRateLimiter::DELETE_NAME, null, '127.0.0.1'));
    }

    public function test_create_uses_60_attempts_per_minute_by_default(): void
    {
        $limit = MediaAssetRateLimiter::forCreate()->limit($this->requestWithUserId(42));

        $this->assertSame(60, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('media-asset-create:user:42', $limit->key);
    }

    public function test_delete_uses_60_attempts_per_minute_by_default(): void
    {
        $limit = MediaAssetRateLimiter::forDelete()->limit($this->requestWithUserId(42));

        $this->assertSame(60, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('media-asset-delete:user:42', $limit->key);
    }

    private function requestWithUserId(int $userId): Request
    {
        $request = Request::create('/api/media-assets', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
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
