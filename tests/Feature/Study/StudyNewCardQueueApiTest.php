<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Support\NewCardQueueReorderRateLimiter;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class StudyNewCardQueueApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/study/new-queue')->assertUnauthorized();
        $this->postJson('/api/study/new-queue/reorder', ['cardIds' => [strtolower((string) str()->ulid())]])
            ->assertUnauthorized();
    }

    public function test_it_lists_the_new_queue_with_a_convolab_compatible_shape(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'front_text' => 'Fallback front',
            'back_text' => 'Fallback back',
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
            'search_text' => '会社 company',
            'source_note_id' => 501,
            'new_queue_position' => 1,
        ]);
        $legacyNullPositionCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'front_text' => 'Legacy display',
            'back_text' => 'legacy meaning',
            'search_text' => '会社 legacy',
            'new_queue_position' => null,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'front_text' => '会社 review',
            'search_text' => '会社 review',
            'new_queue_position' => 2,
        ]);
        $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::New, [
            'front_text' => '会社 other',
            'search_text' => '会社 other',
            'new_queue_position' => 1,
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/new-queue?q='.rawurlencode(' 会社 '));

        $response
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('limit', 100)
            ->assertJsonPath('nextCursor', null)
            ->assertJsonPath('items.0.id', $firstCard->id)
            ->assertJsonPath('items.0.noteId', '501')
            ->assertJsonPath('items.0.cardType', 'recognition')
            ->assertJsonPath('items.0.displayText', '会社')
            ->assertJsonPath('items.0.meaning', 'company')
            ->assertJsonPath('items.0.queuePosition', 1)
            ->assertJsonPath('items.1.id', $legacyNullPositionCard->id)
            ->assertJsonPath('items.1.noteId', $legacyNullPositionCard->id)
            ->assertJsonPath('items.1.displayText', 'Legacy display')
            ->assertJsonPath('items.1.meaning', 'legacy meaning')
            ->assertJsonPath('items.1.queuePosition', null);
    }

    public function test_it_supports_offset_cursor_pagination(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $thirdCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 3,
        ]);

        $firstPage = $this->getJson('/api/study/new-queue?limit=2');

        $firstPage
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('limit', 2)
            ->assertJsonPath('nextCursor', '2')
            ->assertJsonPath('items.0.id', $firstCard->id)
            ->assertJsonPath('items.1.id', $secondCard->id);

        $secondPage = $this->getJson('/api/study/new-queue?cursor=2&limit=2');

        $secondPage
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('nextCursor', null)
            ->assertJsonPath('items.0.id', $thirdCard->id);
    }

    public function test_it_reorders_with_camel_case_card_ids_and_returns_the_default_queue_page(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $response = $this->postJson('/api/study/new-queue/reorder', [
            'cardIds' => [strtoupper($secondCard->id), $firstCard->id],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('limit', 100)
            ->assertJsonPath('nextCursor', null)
            ->assertJsonPath('items.0.id', $secondCard->id)
            ->assertJsonPath('items.0.queuePosition', 1)
            ->assertJsonPath('items.1.id', $firstCard->id)
            ->assertJsonPath('items.1.queuePosition', 2);

        $this->assertDatabaseHas('cards', [
            'id' => $secondCard->id,
            'new_queue_position' => 1,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $firstCard->id,
            'new_queue_position' => 2,
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 2);
    }

    public function test_study_and_canonical_reorders_share_the_same_rate_limit_bucket(): void
    {
        $limiter = new NewCardQueueReorderRateLimiter;
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $restoreNewCardQueueReorderLimiter = function () use ($limiter): void {
            RateLimiter::for(NewCardQueueReorderRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        // Authenticated keys ignore IP, so this matches the request-derived key used below.
        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, null);

        try {
            // CI runs tests serially; this override is process-global and must be restored in finally.
            RateLimiter::for(NewCardQueueReorderRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(1)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            $this
                ->postJson('/api/cards/new/reorder', [
                    'card_ids' => [$secondCard->id, $firstCard->id],
                ])
                ->assertOk();

            $this
                ->postJson('/api/study/new-queue/reorder', [
                    'cardIds' => [$firstCard->id, $secondCard->id],
                ])
                ->assertTooManyRequests();

            $this
                ->getJson('/api/study/new-queue')
                ->assertOk()
                ->assertJsonPath('items.0.id', $secondCard->id)
                ->assertJsonPath('items.1.id', $firstCard->id);

            $this->assertSame(2, $firstCard->refresh()->new_queue_position);
            $this->assertSame(1, $secondCard->refresh()->new_queue_position);
            $this->assertSame(2, SyncFeedEntry::query()->where('user_id', $user->id)->count());
        } finally {
            RateLimiter::clear($userKey);
            $restoreNewCardQueueReorderLimiter();
        }
    }

    public function test_it_validates_convolab_compatible_query_and_body_fields(): void
    {
        $this->signIn();
        $cardId = strtolower((string) str()->ulid());

        $this->getJson('/api/study/new-queue?cursor=-1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor']);

        $this->getJson('/api/study/new-queue?limit=501')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit']);

        $this->postJson('/api/study/new-queue/reorder', ['cardIds' => [$cardId, strtoupper($cardId)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardIds.1']);

        $this->postJson('/api/study/new-queue/reorder', ['cardIds' => ['not-a-ulid']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardIds.0']);
    }
}
