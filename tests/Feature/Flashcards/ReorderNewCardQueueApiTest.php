<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Support\NewCardQueueLimits;
use App\Domain\Flashcards\Support\NewCardQueueReorderRateLimiter;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use App\Support\Pagination\CursorPagination;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ReorderNewCardQueueApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_requires_authentication(): void
    {
        $response = $this->postJson('/api/cards/new/reorder', [
            'card_ids' => [strtolower((string) str()->ulid())],
        ]);

        $response->assertUnauthorized();
    }

    public function test_it_reorders_the_authenticated_users_new_card_queue(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $response = $this->postJson('/api/cards/new/reorder', [
            'card_ids' => [strtoupper($secondCard->id), $firstCard->id],
        ]);

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $secondCard->id)
            ->assertJsonPath('data.0.new_queue_position', 1)
            ->assertJsonPath('data.1.id', $firstCard->id)
            ->assertJsonPath('data.1.new_queue_position', 2);

        $this->assertDatabaseHas('cards', [
            'id' => $secondCard->id,
            'new_queue_position' => 1,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $firstCard->id,
            'new_queue_position' => 2,
        ]);
    }

    public function test_it_accepts_queue_batches_above_the_cursor_page_size(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $cards = collect();

        foreach (range(1, CursorPagination::MAX_PAGE_SIZE + 1) as $position) {
            $cards->push($this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => $position,
            ]));
        }

        $response = $this->postJson('/api/cards/new/reorder', [
            'card_ids' => $cards->pluck('id')->all(),
        ]);

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::MAX_PAGE_SIZE + 1, 'data')
            ->assertJsonPath('data.0.id', $cards->first()->id)
            ->assertJsonPath('data.'.CursorPagination::MAX_PAGE_SIZE.'.id', $cards->last()->id);
    }

    public function test_it_rate_limits_reorders_by_user(): void
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
        $otherUser = User::factory()->create();
        $otherDeck = $this->deckFor($otherUser);
        $otherFirstCard = $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $otherSecondCard = $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $restoreNewCardQueueReorderLimiter = function () use ($limiter): void {
            RateLimiter::for(NewCardQueueReorderRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        // Authenticated keys ignore IP, so these match the request-derived keys used below.
        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, null);
        $otherUserKey = $testBucket.'|'.$limiter->keyFor($otherUser->id, null);

        try {
            // CI runs tests serially; this override is process-global and must be restored in finally.
            RateLimiter::for(NewCardQueueReorderRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(2)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            $this
                ->postJson('/api/cards/new/reorder', [
                    'card_ids' => [$secondCard->id, $firstCard->id],
                ])
                ->assertOk();

            $this
                ->postJson('/api/cards/new/reorder', [
                    'card_ids' => [$firstCard->id, $secondCard->id],
                ])
                ->assertOk();

            $this->signIn($otherUser);

            $this
                ->postJson('/api/cards/new/reorder', [
                    'card_ids' => [$otherSecondCard->id, $otherFirstCard->id],
                ])
                ->assertOk();

            $this->signIn($user);

            $this
                ->postJson('/api/cards/new/reorder', [
                    'card_ids' => [$secondCard->id, $firstCard->id],
                ])
                ->assertTooManyRequests();

            $this->getJson('/api/cards/new')->assertOk();

            $this->assertSame(1, $firstCard->refresh()->new_queue_position);
            $this->assertSame(2, $secondCard->refresh()->new_queue_position);
            $this->assertSame(2, $otherFirstCard->refresh()->new_queue_position);
            $this->assertSame(1, $otherSecondCard->refresh()->new_queue_position);
            $this->assertSame(4, SyncFeedEntry::query()->where('user_id', $user->id)->count());
            $this->assertSame(2, SyncFeedEntry::query()->where('user_id', $otherUser->id)->count());
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreNewCardQueueReorderLimiter();
        }
    }

    public function test_it_rejects_missing_empty_duplicate_and_malformed_card_ids(): void
    {
        $this->signIn();
        $cardId = strtolower((string) str()->ulid());

        $this->postJson('/api/cards/new/reorder', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_ids']);

        $this->postJson('/api/cards/new/reorder', ['card_ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_ids']);

        $this->postJson('/api/cards/new/reorder', ['card_ids' => [$cardId, strtoupper($cardId)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_ids.1']);

        $this->postJson('/api/cards/new/reorder', ['card_ids' => ['not-a-ulid']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_ids.0']);

        $this->postJson('/api/cards/new/reorder', ['card_ids' => [['nested']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_ids.0']);

        $tooManyCardIds = array_map(
            fn (): string => strtolower((string) str()->ulid()),
            range(1, NewCardQueueLimits::PAGE_SIZE_MAX + 1),
        );

        $this->postJson('/api/cards/new/reorder', ['card_ids' => $tooManyCardIds])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_ids']);
    }

    public function test_it_rejects_cross_user_deleted_deck_deleted_card_and_non_new_cards(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $newCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $reviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'new_queue_position' => 2,
        ]);
        $otherUserCard = $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::New, [
            'new_queue_position' => 3,
        ]);
        $deletedDeck = $this->deckFor($user);
        $deletedDeckCard = $this->cardWithStudyStatus($deletedDeck, CardStudyStatus::New, [
            'new_queue_position' => 4,
        ]);
        $deletedDeck->delete();
        $deletedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 5,
        ]);
        $deletedCard->delete();

        foreach ([$reviewCard, $otherUserCard, $deletedDeckCard, $deletedCard] as $invalidCard) {
            $this->postJson('/api/cards/new/reorder', [
                'card_ids' => [$newCard->id, $invalidCard->id],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['card_ids']);
        }

        $this->assertDatabaseHas('cards', [
            'id' => $newCard->id,
            'new_queue_position' => 1,
        ]);
    }
}
