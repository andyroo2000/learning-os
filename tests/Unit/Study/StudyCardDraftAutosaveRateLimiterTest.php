<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyCardDraftAutosaveRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class StudyCardDraftAutosaveRateLimiterTest extends TestCase
{
    public function test_it_builds_stable_user_and_network_keys(): void
    {
        $limiter = new StudyCardDraftAutosaveRateLimiter;

        $this->assertSame('user:42', $limiter->keyFor(42, '127.0.0.1'));
        $this->assertSame('user:42', $limiter->keyFor(42, '192.0.2.10'));
        $this->assertSame('anon:unknown-ip', $limiter->keyFor(null, null));
        $this->assertSame('anon:127.0.0.1', $limiter->keyFor(null, '127.0.0.1'));
        $this->assertSame('user:user-1', $limiter->keyFor('user-1', ''));
        $this->assertSame('user:missing-user', $limiter->keyFor('missing-user', ''));
    }

    public function test_it_uses_120_attempts_per_minute_by_default(): void
    {
        $limiter = new StudyCardDraftAutosaveRateLimiter;
        $request = Request::create('/api/study/card-drafts/'.strtolower((string) str()->ulid()), 'PATCH', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
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
        $this->assertSame('user:42', $limit->key);
    }
}
