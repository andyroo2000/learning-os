<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\PromoteNewCardToFrontAction;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\AssertsCardSyncFeedEntries;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class PromoteNewCardToFrontActionTest extends TestCase
{
    use AssertsCardSyncFeedEntries;
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_promotes_an_owned_new_card_without_reordering_later_cards(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 3,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 7,
        ]);
        $targetCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 11,
        ]);
        $laterCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 20,
        ]);
        $this->assertNotNull($laterCard->updated_at);
        $laterUpdatedAt = $laterCard->updated_at->toJSON();

        $promoted = app(PromoteNewCardToFrontAction::class)->handle(
            userId: $user->id,
            cardId: strtoupper($targetCard->id),
        );

        $this->assertSame($targetCard->id, $promoted->id);
        $this->assertSame(2, $targetCard->refresh()->new_queue_position);
        $this->assertSame(3, $firstCard->refresh()->new_queue_position);
        $this->assertSame(7, $secondCard->refresh()->new_queue_position);
        $this->assertSame(20, $laterCard->refresh()->new_queue_position);
        $this->assertNotNull($laterCard->updated_at);
        $this->assertSame($laterUpdatedAt, $laterCard->updated_at->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 1);
        $this->assertCardSyncPayloadRecorded($targetCard, SyncFeedOperation::Update);
        $this->assertDatabaseMissing('sync_feed_entries', [
            'resource_type' => 'card',
            'resource_id' => $firstCard->id,
        ]);
        $this->assertDatabaseMissing('sync_feed_entries', [
            'resource_type' => 'card',
            'resource_id' => $secondCard->id,
        ]);
        $this->assertDatabaseMissing('sync_feed_entries', [
            'resource_type' => 'card',
            'resource_id' => $laterCard->id,
        ]);
    }

    public function test_it_is_an_idempotent_no_op_when_the_card_is_already_first(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $this->assertNotNull($firstCard->updated_at);
        $updatedAt = $firstCard->updated_at->toJSON();

        $promoted = app(PromoteNewCardToFrontAction::class)->handle($user->id, $firstCard->id);

        $this->assertSame($firstCard->id, $promoted->id);
        $this->assertSame(1, $firstCard->refresh()->new_queue_position);
        $this->assertNotNull($firstCard->updated_at);
        $this->assertSame($updatedAt, $firstCard->updated_at->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_repeated_promotions_allocate_decreasing_signed_positions_one_card_at_a_time(): void
    {
        $user = User::factory()->create();
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

        app(PromoteNewCardToFrontAction::class)->handle($user->id, $thirdCard->id);
        app(PromoteNewCardToFrontAction::class)->handle($user->id, $secondCard->id);

        $this->assertSame(-1, $secondCard->refresh()->new_queue_position);
        $this->assertSame(0, $thirdCard->refresh()->new_queue_position);
        $this->assertSame(1, $firstCard->refresh()->new_queue_position);
        $this->assertDatabaseCount('sync_feed_entries', 2);
        $this->assertCardSyncPayloadRecorded($secondCard, SyncFeedOperation::Update);
        $this->assertCardSyncPayloadRecorded($thirdCard, SyncFeedOperation::Update);
        $this->assertDatabaseMissing('sync_feed_entries', [
            'resource_type' => 'card',
            'resource_id' => $firstCard->id,
        ]);
    }

    public function test_it_assigns_a_position_when_the_first_legacy_card_has_none(): void
    {
        $user = User::factory()->create();
        $legacyCard = $this->cardWithStudyStatus(
            $this->deckFor($user),
            CardStudyStatus::New,
        );

        app(PromoteNewCardToFrontAction::class)->handle($user->id, $legacyCard->id);

        $this->assertSame(0, $legacyCard->refresh()->new_queue_position);
        $this->assertDatabaseCount('sync_feed_entries', 1);
        $this->assertCardSyncPayloadRecorded($legacyCard, SyncFeedOperation::Update);
    }

    public function test_it_promotes_a_legacy_null_position_without_touching_later_legacy_cards(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $positionedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 4,
            'created_at' => Date::parse('2026-01-01 00:00:00'),
        ]);
        $earlierLegacyCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'created_at' => Date::parse('2026-01-02 00:00:00'),
        ]);
        $targetCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'created_at' => Date::parse('2026-01-03 00:00:00'),
        ]);
        $laterLegacyCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'created_at' => Date::parse('2026-01-04 00:00:00'),
        ]);
        $this->assertNotNull($laterLegacyCard->updated_at);
        $laterUpdatedAt = $laterLegacyCard->updated_at->toJSON();

        app(PromoteNewCardToFrontAction::class)->handle($user->id, $targetCard->id);

        $this->assertSame(3, $targetCard->refresh()->new_queue_position);
        $this->assertSame(4, $positionedCard->refresh()->new_queue_position);
        $this->assertNull($earlierLegacyCard->refresh()->new_queue_position);
        $this->assertNull($laterLegacyCard->refresh()->new_queue_position);
        $this->assertNotNull($laterLegacyCard->updated_at);
        $this->assertSame($laterUpdatedAt, $laterLegacyCard->updated_at->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_it_hides_missing_cross_user_deleted_and_ineligible_cards(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $missingId = strtolower((string) str()->ulid());
        $crossUserCard = $this->cardWithStudyStatus(
            $this->deckFor($otherUser),
            CardStudyStatus::New,
            ['new_queue_position' => 1],
        );
        $deletedCard = $this->cardWithStudyStatus(
            $this->deckFor($user),
            CardStudyStatus::New,
            ['new_queue_position' => 1],
        );
        $deletedCard->delete();
        $reviewCard = $this->cardWithStudyStatus(
            $this->deckFor($user),
            CardStudyStatus::Review,
            ['new_queue_position' => null],
        );
        $lockedCard = $this->cardWithStudyStatus(
            $this->deckFor($user),
            CardStudyStatus::New,
            [
                'new_queue_position' => 2,
                'variant_status' => VocabVariantStatus::Locked->value,
            ],
        );

        foreach ([$missingId, $crossUserCard->id, $deletedCard->id, $reviewCard->id, $lockedCard->id] as $cardId) {
            try {
                app(PromoteNewCardToFrontAction::class)->handle($user->id, $cardId);
                $this->fail("Expected {$cardId} to stay hidden.");
            } catch (ModelNotFoundException $exception) {
                $this->assertSame([strtolower($cardId)], $exception->getIds());
            }
        }

        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_hides_malformed_direct_caller_ids_before_opening_a_transaction(): void
    {
        $transactionLevel = DB::transactionLevel();

        try {
            app(PromoteNewCardToFrontAction::class)->handle(
                User::factory()->create()->id,
                'not-a-card-id',
            );
            $this->fail('Expected a malformed card ID to stay hidden.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame([], $exception->getIds());
            $this->assertSame($transactionLevel, DB::transactionLevel());
        }
    }

    public function test_it_rolls_back_card_positions_when_sync_recording_fails(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $targetCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $action = new PromoteNewCardToFrontAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        try {
            $action->handle($user->id, $targetCard->id);
            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertSame(1, $firstCard->refresh()->new_queue_position);
            $this->assertSame(2, $targetCard->refresh()->new_queue_position);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }
}
