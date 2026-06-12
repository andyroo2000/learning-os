<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyCardDraftRetryRateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class StudyCardDraftRetryRateLimiterTest extends TestCase
{
    public function test_it_builds_stable_user_and_network_keys(): void
    {
        $limiter = new StudyCardDraftRetryRateLimiter;

        $this->assertSame('user:42', $limiter->keyFor(42, '127.0.0.1'));
        $this->assertSame('user:42', $limiter->keyFor(42, '192.0.2.10'));
        $this->assertSame('anon:unknown-ip', $limiter->keyFor(null, null));
        $this->assertSame('anon:unknown-ip', $limiter->keyFor(null, ''));
        $this->assertSame('anon:127.0.0.1', $limiter->keyFor('', '127.0.0.1'));
        $this->assertSame('anon:127.0.0.1', $limiter->keyFor(false, '127.0.0.1'));
        $this->assertSame('anon:127.0.0.1', $limiter->keyFor(null, '127.0.0.1'));
        $this->assertSame('user:0', $limiter->keyFor(0, '127.0.0.1'));
        $this->assertSame('user:user-1', $limiter->keyFor('user-1', ''));
        $this->assertSame('user:missing-user', $limiter->keyFor('missing-user', ''));
    }

    public function test_it_uses_30_attempts_per_minute_by_default(): void
    {
        $limiter = new StudyCardDraftRetryRateLimiter;
        $request = Request::create('/api/study/card-drafts/'.strtolower((string) Str::ulid()).'/retry', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->setUserResolver(fn () => new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });

        $limit = $limiter->limit($request);

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('user:42', $limit->key);
    }
}
