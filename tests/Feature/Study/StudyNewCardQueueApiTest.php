<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Support\NewCardQueueLimits;
use App\Domain\Flashcards\Support\NewCardQueueReorderRateLimiter;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    public function test_it_filters_the_new_queue_by_course_and_deck_ids(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeckInCourse = $this->deckFor($user, ['course_id' => $course->id]);
        $outsideCourseDeck = $this->deckFor($user);
        $matchingCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'search_text' => '会社 company',
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($otherDeckInCourse, CardStudyStatus::New, [
            'search_text' => '会社 same course',
            'new_queue_position' => 2,
        ]);
        $this->cardWithStudyStatus($outsideCourseDeck, CardStudyStatus::New, [
            'search_text' => '会社 outside course',
            'new_queue_position' => 3,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'search_text' => '会社 review',
            'new_queue_position' => 4,
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/new-queue?q='.rawurlencode(' 会社 ')
                .'&courseId='.rawurlencode(' '.strtoupper($course->id).' ')
                .'&deckId='.rawurlencode(' '.strtoupper($deck->id).' '));

        $response
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('nextCursor', null)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $matchingCard->id);
    }

    public function test_it_returns_empty_for_cross_user_deck_filters(): void
    {
        $user = $this->signIn();
        $otherDeck = $this->deckFor(User::factory()->create());
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($this->deckFor($user), CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $this->getJson('/api/study/new-queue?deckId='.$otherDeck->id)
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('nextCursor', null)
            ->assertJsonCount(0, 'items');
    }

    public function test_it_lists_equal_queue_positions_by_created_at_before_legacy_null_positions(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $newerCardWithSamePosition = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
            'created_at' => Carbon::parse('2026-06-05T12:00:00Z'),
        ]);
        $olderCardWithSamePosition = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
            'created_at' => Carbon::parse('2026-06-04T12:00:00Z'),
        ]);
        $legacyNullPositionCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => null,
            'created_at' => Carbon::parse('2026-06-03T12:00:00Z'),
        ]);

        $this->getJson('/api/study/new-queue')
            ->assertOk()
            ->assertJsonPath('items.0.id', $olderCardWithSamePosition->id)
            ->assertJsonPath('items.1.id', $newerCardWithSamePosition->id)
            ->assertJsonPath('items.2.id', $legacyNullPositionCard->id);
    }

    public function test_it_accepts_signed_query_integers_without_trim_strings_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/new-queue?cursor=%20%2B1%20&limit=%20%2B1%20')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('limit', 1)
            ->assertJsonPath('nextCursor', null)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $secondCard->id);
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

    public function test_reorder_is_idempotent_when_the_compatible_order_is_unchanged(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $this->assertNotNull($firstCard->updated_at);
        $this->assertNotNull($secondCard->updated_at);
        $firstUpdatedAt = $firstCard->updated_at->toJSON();
        $secondUpdatedAt = $secondCard->updated_at->toJSON();

        $this->postJson('/api/study/new-queue/reorder', [
            'cardIds' => [$firstCard->id, $secondCard->id],
        ])
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonFragment(['nextCursor' => null])
            ->assertJsonPath('items.0.id', $firstCard->id)
            ->assertJsonPath('items.0.queuePosition', 1)
            ->assertJsonPath('items.1.id', $secondCard->id)
            ->assertJsonPath('items.1.queuePosition', 2);

        $firstCard->refresh();
        $secondCard->refresh();

        $this->assertNotNull($firstCard->updated_at);
        $this->assertNotNull($secondCard->updated_at);
        $this->assertSame($firstUpdatedAt, $firstCard->updated_at->toJSON());
        $this->assertSame($secondUpdatedAt, $secondCard->updated_at->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
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
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/new-queue?cursor=%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor']);

        $this->getJson('/api/study/new-queue?limit=501')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit']);
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/new-queue?limit=%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit']);
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/new-queue?courseId=%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['courseId']);
        $this->getJson('/api/study/new-queue?courseId=not-a-ulid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['courseId']);
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/new-queue?deckId=%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deckId']);
        $this->getJson('/api/study/new-queue?deckId=not-a-ulid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deckId']);

        $duplicateResponse = $this->postJson('/api/study/new-queue/reorder', ['cardIds' => [$cardId, strtoupper($cardId)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardIds.1']);

        $this->assertSame('cardIds must not contain duplicates.', $duplicateResponse->json('errors')['cardIds.1'][0]);

        $malformedResponse = $this->postJson('/api/study/new-queue/reorder', ['cardIds' => ['not-a-ulid']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardIds.0']);

        $this->assertSame('Each cardId must be a valid ULID.', $malformedResponse->json('errors')['cardIds.0'][0]);
    }

    public function test_it_returns_camel_case_validation_messages_for_reorder_body_shapes(): void
    {
        $this->signIn();
        $batchLimitMessage = 'cardIds must include between 1 and '.NewCardQueueLimits::PAGE_SIZE_MAX.' cards.';

        $this->postJson('/api/study/new-queue/reorder', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardIds'])
            ->assertJsonPath('errors.cardIds.0', 'cardIds is required.');

        $this->postJson('/api/study/new-queue/reorder', ['cardIds' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardIds'])
            ->assertJsonPath('errors.cardIds.0', $batchLimitMessage);

        $this->postJson('/api/study/new-queue/reorder', ['cardIds' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardIds'])
            ->assertJsonPath('errors.cardIds.0', 'cardIds must be an array.');

        $this->postJson('/api/study/new-queue/reorder', ['cardIds' => 'not-a-list'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardIds'])
            ->assertJsonPath('errors.cardIds.0', 'cardIds must be an array.');

        $nestedResponse = $this->postJson('/api/study/new-queue/reorder', ['cardIds' => [['nested']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardIds.0']);

        $this->assertSame('Each cardId must be a valid ULID.', $nestedResponse->json('errors')['cardIds.0'][0]);

        $tooManyCardIds = array_map(
            fn (): string => strtolower((string) str()->ulid()),
            range(1, NewCardQueueLimits::PAGE_SIZE_MAX + 1),
        );

        $this->postJson('/api/study/new-queue/reorder', ['cardIds' => $tooManyCardIds])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardIds'])
            ->assertJsonPath('errors.cardIds.0', $batchLimitMessage);
    }

    public function test_it_rejects_array_shaped_new_queue_cursor(): void
    {
        $this->signIn();

        $this->getJson('/api/study/new-queue?cursor[]=2')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor']);
    }

    public function test_it_rejects_array_shaped_new_queue_limit(): void
    {
        $this->signIn();

        $this->getJson('/api/study/new-queue?limit[]=2')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit']);
    }

    public function test_it_rejects_array_shaped_new_queue_search_query(): void
    {
        $this->signIn();

        $this->getJson('/api/study/new-queue?q[]=会社')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    }

    public function test_it_rejects_array_shaped_new_queue_course_and_deck_filters(): void
    {
        $this->signIn();

        $this->getJson('/api/study/new-queue?courseId[]=01ktt2q9z5vfpxsqgc3mwrdh35')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['courseId']);

        $this->getJson('/api/study/new-queue?deckId[]=01ktt2q9z5vfpxsqgc3mwrdh35')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deckId']);
    }
}
