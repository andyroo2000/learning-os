<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\NewCardQueuePosition;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Support\CardReviewCardLock;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Advances any staged card family after a newly recorded review.
 *
 * The review writer must lock the owning user before card rows. That shared lock
 * order serializes reviews, card authoring, and queue assignment for a learner.
 * Unlocks and graduation are intentionally monotonic: later failures or review
 * undo do not relock learned stages or reactivate redundant practice cards.
 */
final class AdvanceCardProgressionAfterReviewAction
{
    private const REQUIRED_SUCCESSFUL_RETRIEVALS = 2;

    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
        private readonly ?NewCardQueuePosition $newCardQueuePosition = null,
    ) {}

    public static function supports(Card $card): bool
    {
        return is_string($card->variant_group_id)
            && trim($card->variant_group_id) !== ''
            && is_int($card->variant_stage)
            && $card->variant_stage > 0;
    }

    public function handle(Card $reviewedCard, Carbon $reviewedAt, string $reviewEventId): void
    {
        if (! self::supports($reviewedCard) || ! $reviewedCard->isProgressionAvailable()) {
            return;
        }

        if ($reviewedCard->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Card progression advancement requires an active review transaction.');
        }

        $cards = $this->lockedFamilyCards($reviewedCard);
        $reviewedCardId = (string) $reviewedCard->getKey();
        $liveReviewedCard = $cards->firstWhere('id', $reviewedCardId);

        if (! $liveReviewedCard instanceof Card || ! self::supports($liveReviewedCard)) {
            return;
        }

        $reviewedStage = $liveReviewedCard->variant_stage;
        $highestAvailableStage = $cards
            ->filter(fn (Card $card): bool => $card->isProgressionAvailable())
            ->max('variant_stage');

        // Reviews of an earlier stage must never skip over the currently active stage.
        if ($highestAvailableStage !== $reviewedStage) {
            return;
        }

        $nextStage = $cards
            ->pluck('variant_stage')
            ->filter(fn (?int $stage): bool => $stage !== null && $stage > $reviewedStage)
            ->min();

        if ($nextStage === null && $this->familyHasAlreadyGraduated($cards, $reviewedStage)) {
            return;
        }

        $stageCards = $cards->where('variant_stage', $reviewedStage)->values();

        // A partially unlocked stage is invalid metadata. Fail closed rather than
        // allowing its available subset to advance past locked sibling cards.
        if ($stageCards->isEmpty()
            || $stageCards->contains(fn (Card $card): bool => ! $card->isProgressionAvailable())
            || ! $this->stageHasDemonstratedRetrieval($stageCards, $reviewedAt, $reviewEventId)
        ) {
            return;
        }

        if ($nextStage !== null) {
            $this->unlockStage(
                $cards->where('variant_stage', $nextStage)->values(),
                $reviewedCard->ownerUserId(),
                $reviewedAt,
            );

            return;
        }

        $this->suspendRedundantStages(
            $cards,
            $reviewedStage,
            $reviewedCard->ownerUserId(),
            $reviewedAt,
        );
    }

    /** @return Collection<int, Card> */
    private function lockedFamilyCards(Card $reviewedCard): Collection
    {
        $query = Card::query()
            ->with('deck')
            // Cards in deleted decks are no longer learner-visible family members; keeping
            // them in the gate would make an active family impossible to finish.
            ->whereHas('deck', fn ($query) => $query->where('user_id', $reviewedCard->ownerUserId()))
            ->where('cards.variant_group_id', $reviewedCard->variant_group_id);

        CardReviewCardLock::apply($query->getQuery());

        return $query->get()->values();
    }

    /** @param Collection<int, Card> $stageCards */
    private function stageHasDemonstratedRetrieval(
        Collection $stageCards,
        Carbon $reviewedAt,
        string $reviewEventId,
    ): bool {
        return $stageCards->every(
            fn (Card $card): bool => $this->cardHasDemonstratedRetrieval($card, $reviewedAt, $reviewEventId),
        );
    }

    private function cardHasDemonstratedRetrieval(Card $card, Carbon $reviewedAt, string $reviewEventId): bool
    {
        $latestAgainQuery = CardReviewEvent::query()
            ->where('card_id', $card->id)
            ->where('rating', CardReviewRating::Again->value);
        $successfulReviewQuery = CardReviewEvent::query()
            ->where('card_id', $card->id)
            ->whereIn('rating', [CardReviewRating::Good->value, CardReviewRating::Easy->value]);

        $this->applyReviewChronologyCutoff($latestAgainQuery, $reviewedAt, $reviewEventId);
        $this->applyReviewChronologyCutoff($successfulReviewQuery, $reviewedAt, $reviewEventId);

        if ($card->variant_unlocked_at !== null) {
            $latestAgainQuery->where('reviewed_at', '>=', $card->variant_unlocked_at);
            $successfulReviewQuery->where('reviewed_at', '>=', $card->variant_unlocked_at);
        }

        $latestAgain = $latestAgainQuery
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->first(['reviewed_at', 'id']);

        if ($latestAgain !== null) {
            $successfulReviewQuery->where(function ($query) use ($latestAgain): void {
                $query
                    ->where('reviewed_at', '>', $latestAgain->reviewed_at)
                    ->orWhere(function ($query) use ($latestAgain): void {
                        $query
                            ->where('reviewed_at', $latestAgain->reviewed_at)
                            ->where('id', '>', $latestAgain->id);
                    });
            });
        }

        // Fetch at most the threshold: progression only needs to know whether two
        // qualifying events exist, never the total size of the review history.
        return $successfulReviewQuery
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->limit(self::REQUIRED_SUCCESSFUL_RETRIEVALS)
            ->get(['id'])
            ->count() === self::REQUIRED_SUCCESSFUL_RETRIEVALS;
    }

    /** @param Builder<CardReviewEvent> $query */
    private function applyReviewChronologyCutoff(Builder $query, Carbon $reviewedAt, string $reviewEventId): void
    {
        // Batch rows are bulk-inserted before their card states are applied. Eligibility
        // must only see events at or before the event currently being applied, otherwise
        // a future row in the same offline batch could unlock a stage too early.
        $query->where(function (Builder $query) use ($reviewedAt, $reviewEventId): void {
            $query
                ->where('reviewed_at', '<', $reviewedAt)
                ->orWhere(function (Builder $query) use ($reviewedAt, $reviewEventId): void {
                    $query
                        ->where('reviewed_at', $reviewedAt)
                        ->where('id', '<=', $reviewEventId);
                });
        });
    }

    /** @param Collection<int, Card> $cards */
    private function familyHasAlreadyGraduated(Collection $cards, int $finalStage): bool
    {
        $earlierCards = $cards
            ->filter(fn (Card $card): bool => $card->variant_stage !== null && $card->variant_stage < $finalStage);

        // A one-stage family has no unlock or retirement side effect to perform.
        return $earlierCards->isEmpty()
            || $earlierCards->every(fn (Card $card): bool => $card->variant_status === VocabVariantStatus::Locked->value
                && $card->study_status === CardStudyStatus::Suspended
                && $card->variant_retired_at !== null);
    }

    /** @param Collection<int, Card> $stageCards */
    private function unlockStage(Collection $stageCards, int $userId, Carbon $unlockedAt): void
    {
        $lockedCards = $stageCards
            ->filter(fn (Card $card): bool => $card->variant_status === VocabVariantStatus::Locked->value)
            ->values();

        if ($lockedCards->isEmpty()) {
            return;
        }

        $nextQueuePosition = null;
        foreach ($lockedCards as $card) {
            $card->variant_status = VocabVariantStatus::Available->value;
            $card->variant_unlocked_at = $unlockedAt;
            $card->variant_retired_at = null;

            if (($card->study_status ?? CardStudyStatus::New) === CardStudyStatus::New) {
                // The review writer holds the owner lock for this transaction, so reserving
                // once and incrementing locally cannot collide with another queue writer.
                $nextQueuePosition ??= $this->newCardQueuePosition()->nextForUser($userId);
                $card->new_queue_position = $nextQueuePosition++;
            } else {
                $card->new_queue_position = null;
            }

            $this->saveAndSync($card, $userId);
        }
    }

    /** @param Collection<int, Card> $cards */
    private function suspendRedundantStages(
        Collection $cards,
        int $finalStage,
        int $userId,
        Carbon $retiredAt,
    ): void {
        $cards
            ->filter(fn (Card $card): bool => $card->variant_stage !== null && $card->variant_stage < $finalStage)
            ->each(function (Card $card) use ($retiredAt, $userId): void {
                // Locked + suspended acts as a durable retired state. Review undo restores
                // scheduler snapshots but does not alter variant metadata, so it cannot
                // accidentally return graduated practice to a learner's queue.
                $card->variant_status = VocabVariantStatus::Locked->value;
                $card->variant_retired_at = $retiredAt;
                $card->study_status = CardStudyStatus::Suspended;
                $card->new_queue_position = null;

                if ($card->isDirty([
                    'variant_status',
                    'variant_retired_at',
                    'study_status',
                    'new_queue_position',
                ])) {
                    $this->saveAndSync($card, $userId);
                }
            });
    }

    private function saveAndSync(Card $card, int $userId): void
    {
        $card->saveOrFail();

        $this->recordSyncFeedEntry->handle(
            RecordSyncFeedEntryData::fromInput(
                userId: $userId,
                domain: CardSyncPayload::DOMAIN,
                resourceType: CardSyncPayload::RESOURCE_TYPE,
                resourceId: $card->id,
                operation: SyncFeedOperation::Update->value,
                payload: CardSyncPayload::fromCard($card),
            ),
        );
    }

    private function newCardQueuePosition(): NewCardQueuePosition
    {
        return $this->newCardQueuePosition ?? app(NewCardQueuePosition::class);
    }
}
