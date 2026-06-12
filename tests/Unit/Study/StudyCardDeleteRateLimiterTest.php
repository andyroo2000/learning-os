<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyCardDeleteRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class StudyCardDeleteRateLimiterTest extends TestCase
{
    public function test_it_builds_stable_user_and_network_keys(): void
    {
        $limiter = new StudyCardDeleteRateLimiter;

        $this->assertSame('study-card-delete:user:42', $limiter->keyFor(42, '127.0.0.1'));
        $this->assertSame('study-card-delete:user:42', $limiter->keyFor(42, '192.0.2.10'));
        $this->assertSame('study-card-delete:anon:unknown-ip', $limiter->keyFor(null, null));
        $this->assertSame('study-card-delete:anon:unknown-ip', $limiter->keyFor(null, ''));
        $this->assertSame('study-card-delete:anon:127.0.0.1', $limiter->keyFor('', '127.0.0.1'));
        $this->assertSame('study-card-delete:anon:127.0.0.1', $limiter->keyFor(false, '127.0.0.1'));
        $this->assertSame('study-card-delete:anon:127.0.0.1', $limiter->keyFor(null, '127.0.0.1'));
        $this->assertSame('study-card-delete:anon:192.0.2.10', $limiter->keyFor(null, '192.0.2.10'));
        $this->assertSame('study-card-delete:user:0', $limiter->keyFor(0, '127.0.0.1'));
        $this->assertSame('study-card-delete:user:user-1', $limiter->keyFor('user-1', ''));
        $this->assertSame('study-card-delete:user:missing-user', $limiter->keyFor('missing-user', ''));
    }

    public function test_it_uses_60_attempts_per_minute_by_default(): void
    {
        $limiter = new StudyCardDeleteRateLimiter;
        $request = Request::create('/api/study/cards/01HWZ1KCE7000000000000000', 'DELETE', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
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
        $this->assertSame('study-card-delete:user:42', $limit->key);
    }
}
