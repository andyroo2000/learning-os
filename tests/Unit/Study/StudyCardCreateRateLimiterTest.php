<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class StudyCardCreateRateLimiterTest extends TestCase
{
    public function test_it_builds_stable_user_and_network_keys(): void
    {
        $limiter = new StudyCardCreateRateLimiter;

        $this->assertSame('42|127.0.0.1', $limiter->keyFor(42, '127.0.0.1'));
        $this->assertSame('missing-user|unknown-ip', $limiter->keyFor(null, null));
        $this->assertSame('user-1|unknown-ip', $limiter->keyFor('user-1', ''));
    }

    public function test_it_uses_120_attempts_per_minute_by_default(): void
    {
        $limiter = new StudyCardCreateRateLimiter;
        $request = Request::create('/api/study/cards', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->setUserResolver(fn () => new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });

        $limit = $limiter->limit($request);

        $this->assertSame(120, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('42|127.0.0.1', $limit->key);
    }
}
