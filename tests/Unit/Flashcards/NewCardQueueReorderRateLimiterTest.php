<?php

namespace Tests\Unit\Flashcards;

use App\Domain\Flashcards\Support\NewCardQueueReorderRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class NewCardQueueReorderRateLimiterTest extends TestCase
{
    public function test_it_builds_stable_user_and_network_keys(): void
    {
        $limiter = new NewCardQueueReorderRateLimiter;

        $this->assertSame('new-card-queue-reorder:user:42', $limiter->keyFor(42, '127.0.0.1'));
        $this->assertSame('new-card-queue-reorder:user:42', $limiter->keyFor(42, '192.0.2.10'));
        $this->assertSame('new-card-queue-reorder:anon:unknown-ip', $limiter->keyFor(null, null));
        $this->assertSame('new-card-queue-reorder:anon:unknown-ip', $limiter->keyFor(null, ''));
        $this->assertSame('new-card-queue-reorder:anon:127.0.0.1', $limiter->keyFor('', '127.0.0.1'));
        $this->assertSame('new-card-queue-reorder:anon:127.0.0.1', $limiter->keyFor(false, '127.0.0.1'));
        $this->assertSame('new-card-queue-reorder:anon:127.0.0.1', $limiter->keyFor(null, '127.0.0.1'));
        $this->assertSame('new-card-queue-reorder:anon:192.0.2.10', $limiter->keyFor(null, '192.0.2.10'));
        $this->assertSame('new-card-queue-reorder:user:0', $limiter->keyFor(0, '127.0.0.1'));
        $this->assertSame('new-card-queue-reorder:user:user-1', $limiter->keyFor('user-1', ''));
        $this->assertSame('new-card-queue-reorder:user:str-id', $limiter->keyFor('str-id', ''));
    }

    public function test_it_uses_30_attempts_per_minute_by_default(): void
    {
        $limiter = new NewCardQueueReorderRateLimiter;
        $request = Request::create('/api/cards/new/reorder', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
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
        $this->assertSame('new-card-queue-reorder:user:42', $limit->key);
    }
}
