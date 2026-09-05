<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Support\CardReviewEventCreateRateLimiter;
use App\Domain\Study\Models\StudySettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class StudyReviewClientCompatibilityApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_it_reviews_a_copied_card_by_the_client_id_returned_from_lesson_start(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        Carbon::setTestNow(Carbon::parse('2026-07-16T15:30:00Z'));

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 20,
            ]);
            $clientCardId = '98F42A62-8303-410E-AD4D-5A69C55911BB';
            $canonicalCardId = strtoupper((string) Str::ulid());
            $card = Card::factory()->for($deck)->create([
                'id' => $canonicalCardId,
                'convolab_id' => strtolower($clientCardId),
                'convolab_note_id' => 'c0a8012e-7d2f-4b21-9dd7-14caf2bb1f88',
                'study_status' => CardStudyStatus::New,
                'new_queue_position' => 1,
                'prompt_json' => ['type' => 'text', 'text' => '会社'],
                'answer_json' => ['type' => 'text', 'text' => 'company'],
            ]);

            $sessionResponse = $this->postJson('/api/study/lessons/start');
            $sessionCardId = $sessionResponse->json('cards.0.id');

            $this->assertSame(strtolower($clientCardId), $sessionCardId);
            $this->assertNotSame($card->id, $sessionCardId);

            $response = $this->postJson('/api/study/reviews', [
                'cardId' => '  '.strtoupper($sessionCardId).'  ',
                'grade' => '  GOOD  ',
                'timeZone' => '  America/New_York  ',
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('card.id', strtolower($clientCardId))
                ->assertJsonPath('card.syncId', $canonicalCardId)
                ->assertJsonPath('card.state.queueState', 'learning');

            $reviewLogId = $response->json('reviewLogId');
            $this->assertIsString($reviewLogId);
            $this->assertDatabaseHas('card_review_events', [
                'id' => $reviewLogId,
                'rating' => CardReviewRating::Good->value,
            ]);
            $this->assertSame(
                $canonicalCardId,
                CardReviewEvent::query()->findOrFail($reviewLogId)->card_id,
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_rejects_malformed_client_card_ids_without_review_side_effects(): void
    {
        $this->signIn();

        foreach (['not-a-card', '98f42a62-8303-410e-ad4d-5a69c55911b', ['invalid']] as $cardId) {
            $this->postJson('/api/study/reviews', [
                'cardId' => $cardId,
                'grade' => CardReviewRating::Good->value,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['cardId']);
        }

        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_study_and_canonical_review_creates_share_the_same_rate_limit_bucket(): void
    {
        $limiter = new CardReviewEventCreateRateLimiter;
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $canonicalCard = $this->cardFor($user);
        $studyCard = $this->cardFor($user);

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
                    'card_id' => $canonicalCard->id,
                    'rating' => CardReviewRating::Good->value,
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                ])
                ->assertCreated();

            $this
                ->postJson('/api/study/reviews', [
                    'cardId' => $studyCard->id,
                    'grade' => 'good',
                ])
                ->assertTooManyRequests();

            $this->getJson('/api/study/overview')->assertOk();

            $this->assertSame(1, CardReviewEvent::query()->where('card_id', $canonicalCard->id)->count());
            $this->assertDatabaseMissing('card_review_events', [
                'card_id' => $studyCard->id,
            ]);
        } finally {
            RateLimiter::clear($userKey);
            $restoreCardReviewEventCreateLimiter();
        }
    }

    public function test_it_normalizes_camel_case_inputs_without_global_trim_middleware(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $this->withoutMiddleware(TrimStrings::class);
            $card = $this->cardFor($this->signIn(), [
                'study_status' => CardStudyStatus::Review,
                'due_at' => '2026-06-05T12:00:00Z',
            ]);

            $response = $this->postJson('/api/study/reviews', [
                'cardId' => '  '.strtoupper($card->id).'  ',
                'grade' => '  GOOD  ',
                'timeZone' => '  America/New_York  ',
            ])
                ->assertOk()
                ->assertJsonPath('card.id', $card->id)
                ->assertJsonPath('card.state.queueState', 'review');

            $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json('card'), 'normalized review card payload');
        } finally {
            Carbon::setTestNow();
        }
    }
}
