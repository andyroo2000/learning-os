<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\NewCardQueuePosition;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Actions\AdvanceCardProgressionAfterReviewAction;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\Reviews\BuildsCardProgressionFixtures;
use Tests\Support\Reviews\CardProgressionFixtureStage;
use Tests\TestCase;

class CardProgressionPersistenceTest extends TestCase
{
    use BuildsCardProgressionFixtures;
    use RefreshDatabase;

    public function test_an_already_graduated_family_skips_the_unbounded_review_history_query(): void
    {
        [$user, $deck] = $this->learnerDeck();
        $this->familyCard($deck, 'path-complete', new CardProgressionFixtureStage(1, VocabVariantStatus::Locked), [
            'study_status' => CardStudyStatus::Suspended,
            'variant_retired_at' => '2026-08-25T08:00:00Z',
        ]);
        $finalCard = $this->familyCard($deck, 'path-complete', new CardProgressionFixtureStage(2, VocabVariantStatus::Available), [
            'study_status' => CardStudyStatus::Review,
        ]);
        CardReviewEvent::factory()->count(20)->for($finalCard)->create([
            'rating' => CardReviewRating::Good,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            DB::transaction(function () use ($user, $finalCard): void {
                app(NewCardQueuePosition::class)->lockOwner($user->id);
                $lockedFinalCard = Card::query()
                    ->with('deck')
                    ->whereKey($finalCard->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                app(AdvanceCardProgressionAfterReviewAction::class)->handle(
                    $lockedFinalCard,
                    now(),
                    strtolower((string) Str::ulid()),
                );
            });
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $historyQueries = $queries->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'from "card_review_events"')
                && str_contains($sql, '"rating"');
        });

        $this->assertCount(0, $historyQueries);
    }

    public function test_progression_groups_are_scoped_to_the_card_owner(): void
    {
        [$firstUser, $firstDeck] = $this->learnerDeck();
        [$secondUser, $secondDeck] = $this->learnerDeck();
        $firstUserCurrentCard = $this->familyCard($firstDeck, 'shared-import-group', new CardProgressionFixtureStage(1, VocabVariantStatus::Available));
        $firstUserNextCard = $this->familyCard($firstDeck, 'shared-import-group', new CardProgressionFixtureStage(2, VocabVariantStatus::Locked));
        $secondUserNextCard = $this->familyCard($secondDeck, 'shared-import-group', new CardProgressionFixtureStage(2, VocabVariantStatus::Locked));

        $this->review($firstUserCurrentCard, CardReviewRating::Good, '2026-08-25T09:00:00Z');
        $this->review($firstUserCurrentCard, CardReviewRating::Easy, '2026-08-25T09:05:00Z');

        $this->assertSame(VocabVariantStatus::Available->value, $firstUserNextCard->refresh()->variant_status);
        $this->assertSame(VocabVariantStatus::Locked->value, $secondUserNextCard->refresh()->variant_status);
        $this->assertSame($firstUser->id, $firstUserNextCard->ownerUserId());
        $this->assertSame($secondUser->id, $secondUserNextCard->ownerUserId());
    }

    public function test_unlock_and_review_writes_roll_back_together_when_unlock_sync_fails(): void
    {
        [$user, $deck] = $this->learnerDeck();
        $contextCard = $this->familyCard($deck, 'path-rollback', new CardProgressionFixtureStage(1, VocabVariantStatus::Available));
        $transferCard = $this->familyCard($deck, 'path-rollback', new CardProgressionFixtureStage(2, VocabVariantStatus::Locked));
        CardReviewEvent::factory()->for($contextCard)->create([
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-08-25T09:00:00Z',
        ]);
        $recordSyncFeedEntry = new class($transferCard->id) extends RecordSyncFeedEntryAction
        {
            public function __construct(private readonly string $failingCardId) {}

            public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
            {
                if ($data->resourceType === CardSyncPayload::RESOURCE_TYPE
                    && $data->resourceId === $this->failingCardId
                ) {
                    throw new RuntimeException('Progression card sync failed.');
                }

                return parent::handle($data);
            }
        };

        try {
            (new ReviewCardAction($recordSyncFeedEntry))->handle(ReviewCardData::fromInput(
                cardId: $contextCard->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-08-25T09:05:00Z',
            ));
            $this->fail('Expected progression sync failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Progression card sync failed.', $exception->getMessage());
        }

        $this->assertSame(CardStudyStatus::New, $contextCard->refresh()->study_status);
        $this->assertSame(VocabVariantStatus::Locked->value, $transferCard->refresh()->variant_status);
        $this->assertSame(1, CardReviewEvent::query()->where('card_id', $contextCard->id)->count());
        $this->assertDatabaseCount('sync_feed_entries', 0);
        $this->assertSame($user->id, $contextCard->ownerUserId());
    }

    public function test_a_review_retries_if_progression_metadata_is_added_after_its_preflight_read(): void
    {
        [$user, $deck] = $this->learnerDeck();
        $card = Card::factory()->for($deck)->create();
        $transactionLevelBeforeReview = DB::transactionLevel();
        $reviewCard = new class(app(RecordSyncFeedEntryAction::class)) extends ReviewCardAction
        {
            protected function findCardForUpdate(string $cardId): ?Card
            {
                Card::query()->whereKey($cardId)->update([
                    'variant_group_id' => 'newly-linked-path',
                    'variant_stage' => 1,
                    'variant_status' => VocabVariantStatus::Available->value,
                ]);

                return parent::findCardForUpdate($cardId);
            }
        };

        try {
            $reviewCard->handle(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-08-25T09:05:00Z',
            ));
            $this->fail('Expected a retryable progression lock refresh was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertTrue($exception->isRetryable());
            $this->assertSame('card_review_event_retry', $exception->reason());
        }

        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
        $this->assertNull($card->refresh()->variant_group_id);
        $this->assertSame($user->id, $card->ownerUserId());
        $this->assertSame($transactionLevelBeforeReview, DB::transactionLevel());
    }

    public function test_a_batch_retries_if_progression_metadata_is_added_after_its_owner_preflight(): void
    {
        [$user, $deck] = $this->learnerDeck();
        $card = Card::factory()->for($deck)->create();
        $reviewCards = new class(app(RecordSyncFeedEntryAction::class)) extends ReviewCardBatchAction
        {
            protected function cardsById(Collection $preparedItems): Collection
            {
                Card::query()->whereKey($preparedItems->first()['card_id'])->update([
                    'variant_group_id' => 'newly-linked-batch-path',
                    'variant_stage' => 1,
                    'variant_status' => VocabVariantStatus::Available->value,
                ]);

                return parent::cardsById($preparedItems);
            }
        };

        try {
            $reviewCards->handle([
                ReviewCardData::fromInput(
                    cardId: $card->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-08-25T09:05:00Z',
                    clientEventId: 'batch-owner-race',
                    deviceId: 'batch-owner-race-device',
                    clientCreatedAt: '2026-08-25T09:05:00Z',
                ),
            ]);
            $this->fail('Expected a retryable batch progression lock refresh was not thrown.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertTrue($exception->isRetryable());
            $this->assertSame('card_review_event_retry', $exception->reason());
        }

        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
        $this->assertNull($card->refresh()->variant_group_id);
        $this->assertSame($user->id, $card->ownerUserId());
    }
}
