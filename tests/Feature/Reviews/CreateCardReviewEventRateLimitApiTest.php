<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Support\CardReviewEventCreateRateLimiter;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class CreateCardReviewEventRateLimitApiTest extends CreateCardReviewEventApiTestCase
{
    public function test_it_rate_limits_review_event_writes_by_user(): void
    {
        $limiter = new CardReviewEventCreateRateLimiter;
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $firstCard = $this->cardFor($user);
        $secondCard = $this->cardFor($user);
        $thirdCard = $this->cardFor($user);
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser);

        $restoreCardReviewEventCreateLimiter = function () use ($limiter): void {
            RateLimiter::for(CardReviewEventCreateRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        // Authenticated keys ignore IP, so these match the request-derived keys used below.
        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, null);
        $otherUserKey = $testBucket.'|'.$limiter->keyFor($otherUser->id, null);

        try {
            // CI runs tests serially; this override is process-global and must be restored in finally.
            RateLimiter::for(CardReviewEventCreateRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(2)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            $this
                ->postJson('/api/card-review-events', [
                    'card_id' => $firstCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                ])
                ->assertCreated();

            $this
                ->postJson('/api/card-review-events', [
                    'card_id' => $secondCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                ])
                ->assertCreated();

            $this->signIn($otherUser);

            $this
                ->postJson('/api/card-review-events', [
                    'card_id' => $otherCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:25:00Z',
                ])
                ->assertCreated();

            $this->signIn($user);

            $this
                ->postJson('/api/card-review-events', [
                    'card_id' => $thirdCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:30:00Z',
                ])
                ->assertTooManyRequests()
                ->assertHeader('X-RateLimit-Limit', '2')
                ->assertHeader('X-RateLimit-Remaining', '0')
                ->assertHeader('Retry-After');

            $this->getJson('/api/card-review-events')->assertOk();

            $this->assertSame(2, CardReviewEvent::query()->whereHas('card.deck', fn ($query) => $query->where('user_id', $user->id))->count());
            $this->assertSame(1, CardReviewEvent::query()->whereHas('card.deck', fn ($query) => $query->where('user_id', $otherUser->id))->count());
            $this->assertDatabaseMissing('card_review_events', [
                'card_id' => $thirdCard->id,
            ]);
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreCardReviewEventCreateLimiter();
        }
    }
}
