<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Support\CardReviewEventCreateRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardReviewEventBatchRateLimitApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_replay_shares_the_review_write_rate_limit_bucket(): void
    {
        $limiter = new CardReviewEventCreateRateLimiter;
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $singleCard = $this->cardFor($user);
        $batchCard = $this->cardFor($user);

        $restoreCardReviewEventCreateLimiter = function () use ($limiter): void {
            RateLimiter::for(CardReviewEventCreateRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        // Authenticated keys ignore IP, so this matches the request-derived key used below.
        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, null);

        try {
            // CI runs tests serially; this override is process-global and must be restored in finally.
            RateLimiter::for(CardReviewEventCreateRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(1)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            $this
                ->postJson('/api/card-review-events', [
                    'card_id' => $singleCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                ])
                ->assertCreated();

            $this
                ->postJson('/api/card-review-events/batch', [
                    'events' => [
                        [
                            'card_id' => $batchCard->id,
                            'rating' => CardReviewRating::Good->value,
                            'reviewed_at' => '2026-05-27T09:20:00Z',
                            'client_event_id' => 'event-456',
                            'device_id' => 'device-abc',
                            'client_created_at' => '2026-05-27T09:19:00Z',
                        ],
                    ],
                ])
                ->assertTooManyRequests()
                ->assertHeader('X-RateLimit-Limit', '1')
                ->assertHeader('X-RateLimit-Remaining', '0')
                ->assertHeader('Retry-After');

            $this->getJson('/api/card-review-events')->assertOk();

            $this->assertSame(1, CardReviewEvent::query()->where('card_id', $singleCard->id)->count());
            $this->assertDatabaseMissing('card_review_events', [
                'card_id' => $batchCard->id,
            ]);
        } finally {
            RateLimiter::clear($userKey);
            $restoreCardReviewEventCreateLimiter();
        }
    }
}
