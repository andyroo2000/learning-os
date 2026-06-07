<?php

namespace Tests\Unit\Reviews;

use App\Domain\Reviews\Support\CardReviewEventUndoRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class CardReviewEventUndoRateLimiterTest extends TestCase
{
    public function test_it_builds_stable_user_and_network_keys(): void
    {
        $limiter = new CardReviewEventUndoRateLimiter;

        $this->assertSame('card-review-event-undo:user:42', $limiter->keyFor(42, '127.0.0.1'));
        $this->assertSame('card-review-event-undo:user:42', $limiter->keyFor(42, '192.0.2.10'));
        $this->assertSame('card-review-event-undo:anon:unknown-ip', $limiter->keyFor(null, null));
        $this->assertSame('card-review-event-undo:anon:unknown-ip', $limiter->keyFor(null, ''));
        $this->assertSame('card-review-event-undo:anon:127.0.0.1', $limiter->keyFor(null, '127.0.0.1'));
        $this->assertSame('card-review-event-undo:anon:192.0.2.10', $limiter->keyFor(null, '192.0.2.10'));
        $this->assertSame('card-review-event-undo:user:user-1', $limiter->keyFor('user-1', ''));
        $this->assertSame('card-review-event-undo:user:str-id', $limiter->keyFor('str-id', ''));
    }

    public function test_it_uses_30_attempts_per_minute_by_default(): void
    {
        $limiter = new CardReviewEventUndoRateLimiter;
        $request = Request::create('/api/card-review-events/'.strtolower((string) str()->ulid()), 'DELETE', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
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
        $this->assertSame('card-review-event-undo:user:42', $limit->key);
    }
}
