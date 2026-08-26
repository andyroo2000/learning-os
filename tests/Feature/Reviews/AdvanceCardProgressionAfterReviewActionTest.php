<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Enums\CardProgressionUnlockRequirement;
use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Support\NewCardQueuePosition;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Actions\AdvanceCardProgressionAfterReviewAction;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Actions\UndoCardReviewEventAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AdvanceCardProgressionAfterReviewActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite models a fixed review chronology on August 25, 2026.
        Carbon::setTestNow('2026-08-25T12:00:00Z');
    }

    public function test_guru_and_master_requirements_use_the_reviewed_cards_current_fsrs_mastery(): void
    {
        [$user, $deck] = $this->learnerDeck();
        $guruPredecessor = $this->familyCard($deck, 'path-guru', 1, VocabVariantStatus::Available, [
            'study_status' => CardStudyStatus::Review,
            'scheduler_state' => ['stability' => 6.99, 'difficulty' => 5],
        ]);
        $guruSuccessor = $this->familyCard($deck, 'path-guru', 2, VocabVariantStatus::Locked, [
            'variant_unlock_requirement' => CardProgressionUnlockRequirement::Guru,
        ]);
        $masterPredecessor = $this->familyCard($deck, 'path-master', 1, VocabVariantStatus::Available, [
            'study_status' => CardStudyStatus::Review,
            'scheduler_state' => ['stability' => 29.99, 'difficulty' => 5],
        ]);
        $masterSuccessor = $this->familyCard($deck, 'path-master', 2, VocabVariantStatus::Locked, [
            'variant_unlock_requirement' => CardProgressionUnlockRequirement::Master,
        ]);

        $this->advance($guruPredecessor);
        $this->advance($masterPredecessor);
        $this->assertSame(VocabVariantStatus::Locked->value, $guruSuccessor->refresh()->variant_status);
        $this->assertSame(VocabVariantStatus::Locked->value, $masterSuccessor->refresh()->variant_status);

        $guruPredecessor->forceFill(['scheduler_state' => ['stability' => 7, 'difficulty' => 5]])->saveQuietly();
        $masterPredecessor->forceFill(['scheduler_state' => ['stability' => 30, 'difficulty' => 5]])->saveQuietly();

        $this->advance($guruPredecessor);
        $this->advance($masterPredecessor, CardReviewRating::Again);
        $this->assertSame(VocabVariantStatus::Available->value, $guruSuccessor->refresh()->variant_status);
        $this->assertSame(VocabVariantStatus::Locked->value, $masterSuccessor->refresh()->variant_status);

        $this->advance($masterPredecessor);
        $this->assertSame(VocabVariantStatus::Available->value, $masterSuccessor->refresh()->variant_status);
        $this->assertSame($user->id, $masterSuccessor->ownerUserId());
    }

    public function test_every_card_in_the_active_stage_needs_two_successes_before_the_next_sparse_stage_unlocks(): void
    {
        Carbon::setTestNow('2026-08-25T12:00:00Z');

        [$user, $deck] = $this->learnerDeck();
        Card::factory()->for($deck)->create(['new_queue_position' => 4]);
        $firstContextCard = $this->familyCard($deck, 'path-1', 1, VocabVariantStatus::Available);
        $secondContextCard = $this->familyCard($deck, 'path-1', 1, VocabVariantStatus::Available);
        $firstTransferCard = $this->familyCard($deck, 'path-1', 3, VocabVariantStatus::Locked);
        $secondTransferCard = $this->familyCard($deck, 'path-1', 3, VocabVariantStatus::Locked);

        $this->review($firstContextCard, CardReviewRating::Good, '2026-08-25T09:00:00Z');
        $this->review($firstContextCard, CardReviewRating::Easy, '2026-08-25T09:05:00Z');
        $this->review($secondContextCard, CardReviewRating::Good, '2026-08-25T09:10:00Z');

        $this->assertSame(VocabVariantStatus::Locked->value, $firstTransferCard->refresh()->variant_status);
        $this->assertSame(VocabVariantStatus::Locked->value, $secondTransferCard->refresh()->variant_status);

        $this->review($secondContextCard, CardReviewRating::Good, '2026-08-25T09:15:00Z');

        $this->assertSame(VocabVariantStatus::Available->value, $firstTransferCard->refresh()->variant_status);
        $this->assertSame(VocabVariantStatus::Available->value, $secondTransferCard->refresh()->variant_status);
        $this->assertSame('2026-08-25T09:15:00.000000Z', $firstTransferCard->variant_unlocked_at?->toJSON());
        $this->assertSame('2026-08-25T09:15:00.000000Z', $secondTransferCard->variant_unlocked_at?->toJSON());
        $this->assertSame(5, $firstTransferCard->new_queue_position);
        $this->assertSame(6, $secondTransferCard->new_queue_position);

        foreach ([$firstTransferCard, $secondTransferCard] as $unlockedCard) {
            $entry = SyncFeedEntry::query()
                ->where('user_id', $user->id)
                ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
                ->where('resource_id', $unlockedCard->id)
                ->sole();

            $this->assertSame(VocabVariantStatus::Available->value, $entry->payload['variant_status']);
            $this->assertSame($unlockedCard->new_queue_position, $entry->payload['new_queue_position']);
        }
    }

    public function test_again_resets_successes_and_reviews_before_the_unlock_boundary_do_not_count(): void
    {
        [$user, $deck] = $this->learnerDeck();
        $currentCard = $this->familyCard(
            $deck,
            'path-reset',
            1,
            VocabVariantStatus::Available,
            ['variant_unlocked_at' => '2026-08-25T09:00:00Z'],
        );
        $nextCard = $this->familyCard($deck, 'path-reset', 2, VocabVariantStatus::Locked);

        CardReviewEvent::factory()->for($currentCard)->create([
            'rating' => CardReviewRating::Good,
            'reviewed_at' => '2026-08-25T08:00:00Z',
        ]);
        CardReviewEvent::factory()->for($currentCard)->create([
            'rating' => CardReviewRating::Easy,
            'reviewed_at' => '2026-08-25T08:05:00Z',
        ]);

        $this->review($currentCard, CardReviewRating::Good, '2026-08-25T09:05:00Z');
        $this->review($currentCard, CardReviewRating::Again, '2026-08-25T09:10:00Z');
        $this->review($currentCard, CardReviewRating::Good, '2026-08-25T09:15:00Z');

        $this->assertSame(VocabVariantStatus::Locked->value, $nextCard->refresh()->variant_status);

        $this->review($currentCard, CardReviewRating::Easy, '2026-08-25T09:20:00Z');

        $this->assertSame(VocabVariantStatus::Available->value, $nextCard->refresh()->variant_status);
        $this->assertSame($user->id, $nextCard->ownerUserId());
    }

    public function test_transfer_siblings_receive_fresh_priority_windows_and_second_listening_waits_a_day(): void
    {
        Carbon::setTestNow('2026-08-25T12:00:00Z');
        [, $deck] = $this->learnerDeck();
        $common = ['selection_policy' => CardSelectionPolicy::Sprinkled];
        $firstListening = $this->familyCard($deck, 'transfer-spacing', 1, VocabVariantStatus::Available, [
            ...$common,
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'introduced_at' => '2026-08-25T08:00:00Z',
        ]);
        $recognition = $this->familyCard($deck, 'transfer-spacing', 2, VocabVariantStatus::Locked, [
            ...$common,
            'variant_kind' => VocabVariantKind::SentenceTextRecognition,
        ]);
        $cloze = $this->familyCard($deck, 'transfer-spacing', 3, VocabVariantStatus::Locked, [
            ...$common,
            'variant_kind' => VocabVariantKind::SentenceCloze,
        ]);
        $secondListening = $this->familyCard($deck, 'transfer-spacing', 4, VocabVariantStatus::Locked, [
            ...$common,
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
        ]);

        $this->review($firstListening, CardReviewRating::Good, '2026-08-25T09:00:00Z');
        $this->review($firstListening, CardReviewRating::Easy, '2026-08-25T09:05:00Z');
        $this->assertSame('2026-09-01T09:05:00.000000Z', $recognition->refresh()->priority_until?->toJSON());

        $this->review($recognition, CardReviewRating::Good, '2026-08-25T09:10:00Z');
        $this->review($recognition, CardReviewRating::Easy, '2026-08-25T09:15:00Z');
        $this->review($cloze, CardReviewRating::Good, '2026-08-25T09:20:00Z');
        $this->review($cloze, CardReviewRating::Easy, '2026-08-25T09:25:00Z');

        $secondListening->refresh();
        $this->assertSame(VocabVariantStatus::Available->value, $secondListening->variant_status);
        $this->assertSame('2026-08-26T08:00:00.000000Z', $secondListening->introduction_available_at?->toJSON());
        $this->assertSame('2026-09-02T08:00:00.000000Z', $secondListening->priority_until?->toJSON());
        $this->assertNotNull($secondListening->new_queue_position);
    }

    public function test_a_batch_can_satisfy_the_threshold_and_an_exact_retry_does_not_repeat_unlock_side_effects(): void
    {
        [$user, $deck] = $this->learnerDeck();
        $contextCard = $this->familyCard($deck, 'path-batch', 1, VocabVariantStatus::Available);
        $transferCard = $this->familyCard($deck, 'path-batch', 2, VocabVariantStatus::Locked);
        $firstEventId = strtolower((string) Str::ulid());
        $secondEventId = strtolower((string) Str::ulid());
        $items = [
            ReviewCardData::fromInput(
                cardId: $contextCard->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-08-25T09:00:00Z',
                id: $firstEventId,
                clientEventId: 'path-batch-1',
                deviceId: 'progression-test',
                clientCreatedAt: '2026-08-25T09:00:00Z',
            ),
            ReviewCardData::fromInput(
                cardId: $contextCard->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-08-25T09:05:00Z',
                id: $secondEventId,
                clientEventId: 'path-batch-2',
                deviceId: 'progression-test',
                clientCreatedAt: '2026-08-25T09:05:00Z',
            ),
        ];

        $firstResult = app(ReviewCardBatchAction::class)->handle($items);
        $secondResult = app(ReviewCardBatchAction::class)->handle($items);

        $this->assertTrue($firstResult->hasCreatedEvents);
        $this->assertFalse($secondResult->hasCreatedEvents);
        $this->assertSame(VocabVariantStatus::Available->value, $transferCard->refresh()->variant_status);
        $this->assertSame(1, SyncFeedEntry::query()
            ->where('user_id', $user->id)
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $transferCard->id)
            ->count());
    }

    public function test_an_offline_batch_can_review_a_stage_unlocked_by_earlier_events_in_the_same_batch(): void
    {
        [$user, $deck] = $this->learnerDeck();
        $contextCard = $this->familyCard($deck, 'path-offline', 1, VocabVariantStatus::Available);
        $textCard = $this->familyCard($deck, 'path-offline', 2, VocabVariantStatus::Locked);
        $wordCard = $this->familyCard($deck, 'path-offline', 3, VocabVariantStatus::Locked);
        $textReviewId = strtolower((string) Str::ulid());
        $wordReviewId = strtolower((string) Str::ulid());

        $result = app(ReviewCardBatchAction::class)->handle([
            ReviewCardData::fromInput(
                cardId: $contextCard->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-08-25T09:00:00Z',
                clientEventId: 'offline-context-1',
                deviceId: 'offline-progression-test',
                clientCreatedAt: '2026-08-25T09:00:00Z',
            ),
            ReviewCardData::fromInput(
                cardId: $contextCard->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-08-25T09:05:00Z',
                clientEventId: 'offline-context-2',
                deviceId: 'offline-progression-test',
                clientCreatedAt: '2026-08-25T09:05:00Z',
            ),
            ReviewCardData::fromInput(
                cardId: $textCard->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-08-25T09:10:00Z',
                id: $textReviewId,
                clientEventId: 'offline-text-1',
                deviceId: 'offline-progression-test',
                clientCreatedAt: '2026-08-25T09:10:00Z',
            ),
            ReviewCardData::fromInput(
                cardId: $textCard->id,
                rating: CardReviewRating::Easy->value,
                reviewedAt: '2026-08-25T09:15:00Z',
                clientEventId: 'offline-text-2',
                deviceId: 'offline-progression-test',
                clientCreatedAt: '2026-08-25T09:15:00Z',
            ),
            ReviewCardData::fromInput(
                cardId: $wordCard->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: '2026-08-25T09:20:00Z',
                id: $wordReviewId,
                clientEventId: 'offline-word-1',
                deviceId: 'offline-progression-test',
                clientCreatedAt: '2026-08-25T09:20:00Z',
            ),
        ]);

        $this->assertTrue($result->hasCreatedEvents);
        $this->assertCount(5, $result->reviewEvents);
        $this->assertSame(VocabVariantStatus::Available->value, $textCard->refresh()->variant_status);
        $this->assertSame('2026-08-25T09:05:00.000000Z', $textCard->variant_unlocked_at?->toJSON());
        $this->assertNull($textCard->new_queue_position);
        $this->assertSame(VocabVariantStatus::Available->value, $wordCard->refresh()->variant_status);
        $this->assertSame('2026-08-25T09:15:00.000000Z', $wordCard->variant_unlocked_at?->toJSON());
        $this->assertSame(CardStudyStatus::Learning, $wordCard->study_status);
        $this->assertNull($wordCard->new_queue_position);

        $textReview = CardReviewEvent::query()->findOrFail($textReviewId);
        $this->assertSame(CardStudyStatus::New->value, $textReview->card_state_before['study_status']);
        $this->assertSame(1, $textReview->card_state_before['new_queue_position']);
        $wordReview = CardReviewEvent::query()->findOrFail($wordReviewId);
        $this->assertSame(CardStudyStatus::New->value, $wordReview->card_state_before['study_status']);
        $this->assertSame(1, $wordReview->card_state_before['new_queue_position']);
        $this->assertSame($user->id, $textCard->ownerUserId());
    }

    public function test_an_offline_batch_cannot_use_a_future_event_to_unlock_an_earlier_locked_review(): void
    {
        [$user, $deck] = $this->learnerDeck();
        $contextCard = $this->familyCard($deck, 'path-offline-order', 1, VocabVariantStatus::Available);
        $textCard = $this->familyCard($deck, 'path-offline-order', 2, VocabVariantStatus::Locked);

        try {
            app(ReviewCardBatchAction::class)->handle([
                ReviewCardData::fromInput(
                    cardId: $contextCard->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-08-25T09:00:00Z',
                    clientEventId: 'offline-order-context-1',
                    deviceId: 'offline-order-test',
                    clientCreatedAt: '2026-08-25T09:00:00Z',
                ),
                ReviewCardData::fromInput(
                    cardId: $textCard->id,
                    rating: CardReviewRating::Good->value,
                    reviewedAt: '2026-08-25T09:05:00Z',
                    clientEventId: 'offline-order-text-1',
                    deviceId: 'offline-order-test',
                    clientCreatedAt: '2026-08-25T09:05:00Z',
                ),
                ReviewCardData::fromInput(
                    cardId: $contextCard->id,
                    rating: CardReviewRating::Easy->value,
                    reviewedAt: '2026-08-25T09:10:00Z',
                    clientEventId: 'offline-order-context-2',
                    deviceId: 'offline-order-test',
                    clientCreatedAt: '2026-08-25T09:10:00Z',
                ),
            ]);
            $this->fail('Expected the chronologically premature locked-stage review to be rejected.');
        } catch (CardReviewEventConflictException $exception) {
            $this->assertSame('card_progression_locked', $exception->reason());
        }

        $this->assertSame(CardStudyStatus::New, $contextCard->refresh()->study_status);
        $this->assertSame(VocabVariantStatus::Locked->value, $textCard->refresh()->variant_status);
        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
        $this->assertSame($user->id, $textCard->ownerUserId());
    }

    public function test_reviews_of_an_earlier_stage_cannot_skip_over_the_highest_available_stage(): void
    {
        [$user, $deck] = $this->learnerDeck();
        $firstStage = $this->familyCard($deck, 'path-no-skip', 1, VocabVariantStatus::Available);
        $secondStage = $this->familyCard($deck, 'path-no-skip', 2, VocabVariantStatus::Available);
        $thirdStage = $this->familyCard($deck, 'path-no-skip', 3, VocabVariantStatus::Locked);

        $this->review($firstStage, CardReviewRating::Good, '2026-08-25T09:00:00Z');
        $this->review($firstStage, CardReviewRating::Easy, '2026-08-25T09:05:00Z');

        $this->assertSame(VocabVariantStatus::Locked->value, $thirdStage->refresh()->variant_status);

        $this->review($secondStage, CardReviewRating::Good, '2026-08-25T09:10:00Z');
        $this->review($secondStage, CardReviewRating::Easy, '2026-08-25T09:15:00Z');

        $this->assertSame(VocabVariantStatus::Available->value, $thirdStage->refresh()->variant_status);
        $this->assertSame($user->id, $thirdStage->ownerUserId());
    }

    public function test_final_stage_transfer_suspends_earlier_variants_but_keeps_the_transfer_stage_active(): void
    {
        [$user, $deck] = $this->learnerDeck();
        $listeningCard = $this->familyCard($deck, 'path-graduate', 1, VocabVariantStatus::Available, [
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-08-25T08:00:00Z',
        ]);
        $recognitionCard = $this->familyCard($deck, 'path-graduate', 2, VocabVariantStatus::Available, [
            'study_status' => CardStudyStatus::Learning,
        ]);
        $transferCard = $this->familyCard($deck, 'path-graduate', 5, VocabVariantStatus::Available);

        $this->review($listeningCard, CardReviewRating::Good, '2026-08-25T08:30:00Z');
        $this->review($transferCard, CardReviewRating::Good, '2026-08-25T09:00:00Z');
        $this->review($transferCard, CardReviewRating::Easy, '2026-08-25T09:05:00Z');

        $this->assertSame(CardStudyStatus::Suspended, $listeningCard->refresh()->study_status);
        $this->assertSame(CardStudyStatus::Suspended, $recognitionCard->refresh()->study_status);
        $this->assertNotSame(CardStudyStatus::Suspended, $transferCard->refresh()->study_status);
        $this->assertSame(VocabVariantStatus::Locked->value, $listeningCard->variant_status);
        $this->assertSame(VocabVariantStatus::Locked->value, $recognitionCard->variant_status);
        $this->assertSame('2026-08-25T09:05:00.000000Z', $listeningCard->variant_retired_at?->toJSON());
        $this->assertSame('2026-08-25T09:05:00.000000Z', $recognitionCard->variant_retired_at?->toJSON());
        $this->assertNull($listeningCard->new_queue_position);
        $this->assertNull($recognitionCard->new_queue_position);

        foreach ([$listeningCard, $recognitionCard] as $suspendedCard) {
            $this->assertTrue(SyncFeedEntry::query()
                ->where('user_id', $user->id)
                ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
                ->where('resource_id', $suspendedCard->id)
                ->where('payload->study_status', CardStudyStatus::Suspended->value)
                ->where('payload->variant_retired_at', '2026-08-25T09:05:00.000000Z')
                ->exists());
        }

        app(UndoCardReviewEventAction::class)->handle(
            CardReviewEvent::query()->where('card_id', $listeningCard->id)->sole(),
        );

        $this->assertFalse($listeningCard->refresh()->isProgressionAvailable());
        $this->assertSame(CardStudyStatus::Suspended, $listeningCard->study_status);
        $this->assertNull($listeningCard->new_queue_position);

        $this->review($transferCard, CardReviewRating::Again, '2026-08-25T09:10:00Z');

        $this->assertFalse($listeningCard->refresh()->isProgressionAvailable());
        $this->assertSame(CardStudyStatus::Suspended, $recognitionCard->refresh()->study_status);
    }

    public function test_an_already_graduated_family_skips_the_unbounded_review_history_query(): void
    {
        [$user, $deck] = $this->learnerDeck();
        $this->familyCard($deck, 'path-complete', 1, VocabVariantStatus::Locked, [
            'study_status' => CardStudyStatus::Suspended,
            'variant_retired_at' => '2026-08-25T08:00:00Z',
        ]);
        $finalCard = $this->familyCard($deck, 'path-complete', 2, VocabVariantStatus::Available, [
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
        $firstUserCurrentCard = $this->familyCard($firstDeck, 'shared-import-group', 1, VocabVariantStatus::Available);
        $firstUserNextCard = $this->familyCard($firstDeck, 'shared-import-group', 2, VocabVariantStatus::Locked);
        $secondUserNextCard = $this->familyCard($secondDeck, 'shared-import-group', 2, VocabVariantStatus::Locked);

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
        $contextCard = $this->familyCard($deck, 'path-rollback', 1, VocabVariantStatus::Available);
        $transferCard = $this->familyCard($deck, 'path-rollback', 2, VocabVariantStatus::Locked);
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

    /** @return array{User, Deck} */
    private function learnerDeck(): array
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create(['user_id' => $user->id]);

        return [$user, $deck];
    }

    /** @param array<string, mixed> $attributes */
    private function familyCard(
        Deck $deck,
        string $groupId,
        int $stage,
        VocabVariantStatus $status,
        array $attributes = [],
    ): Card {
        return Card::factory()->for($deck)->create([
            'variant_group_id' => $groupId,
            'variant_stage' => $stage,
            'variant_status' => $status->value,
            'variant_unlocked_at' => $status === VocabVariantStatus::Available ? now()->subDay() : null,
            'new_queue_position' => null,
            ...$attributes,
        ]);
    }

    private function review(Card $card, CardReviewRating $rating, string $reviewedAt): void
    {
        app(ReviewCardAction::class)->handle(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: $rating->value,
            reviewedAt: $reviewedAt,
        ));
    }

    private function advance(Card $card, CardReviewRating $rating = CardReviewRating::Good): void
    {
        $reviewEvent = CardReviewEvent::factory()->for($card)->create([
            'rating' => $rating->value,
            'reviewed_at' => now(),
        ]);

        DB::transaction(function () use ($card, $reviewEvent): void {
            app(NewCardQueuePosition::class)->lockOwner($card->ownerUserId());
            $lockedCard = Card::query()
                ->with('deck')
                ->whereKey($card->id)
                ->lockForUpdate()
                ->firstOrFail();

            app(AdvanceCardProgressionAfterReviewAction::class)->handle(
                $lockedCard,
                $reviewEvent->reviewed_at,
                $reviewEvent->id,
            );
        });
    }
}
