<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudySessionStartRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class StudySessionStartRateLimiterTest extends TestCase
{
    public function test_it_builds_stable_user_and_network_keys(): void
    {
        $limiter = new StudySessionStartRateLimiter;

        $this->assertSame('study-session-start:user:42', $limiter->keyFor(42, '127.0.0.1'));
        $this->assertSame('study-session-start:user:42', $limiter->keyFor(42, '192.0.2.10'));
        $this->assertSame('study-session-start:anon:unknown-ip', $limiter->keyFor(null, null));
        $this->assertSame('study-session-start:anon:unknown-ip', $limiter->keyFor(null, ''));
        $this->assertSame('study-session-start:anon:127.0.0.1', $limiter->keyFor(null, '127.0.0.1'));
        $this->assertSame('study-session-start:anon:127.0.0.1', $limiter->keyFor('', '127.0.0.1'));
        $this->assertSame('study-session-start:anon:127.0.0.1', $limiter->keyFor(0, '127.0.0.1'));
        $this->assertSame('study-session-start:anon:127.0.0.1', $limiter->keyFor('0', '127.0.0.1'));
        $this->assertSame('study-session-start:user:user-1', $limiter->keyFor('user-1', ''));
    }

    public function test_it_uses_60_attempts_per_minute_by_default(): void
    {
        $limiter = new StudySessionStartRateLimiter;
        $request = Request::create('/api/study/session/start', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->setUserResolver(fn () => new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });

        $limit = $limiter->limit($request);

        $this->assertSame(60, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('study-session-start:user:42', $limit->key);
    }
}
