<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Enums\CardProgressionUnlockRequirement;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Exceptions\LearningPathConflictException;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\NewCardQueuePosition;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Support\CardReviewCardLock;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds one card as the next stage of a learner-authored linear learning path.
 *
 * The owning user is locked before card rows, matching review and queue writers.
 * Existing generated families remain compatible, but this authoring surface fails
 * closed on partial metadata instead of trying to repair ambiguous relationships.
 */
final class LinkCardLearningPathSuccessorAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
        private readonly ?NewCardQueuePosition $newCardQueuePosition = null,
    ) {}

    /** @return Collection<int, Card> */
    public function handle(
        Card $predecessor,
        Card $successor,
        CardProgressionUnlockRequirement $unlockRequirement = CardProgressionUnlockRequirement::SuccessfulRetrieval,
    ): Collection {
        if ((string) $predecessor->getKey() === (string) $successor->getKey()) {
            throw LearningPathConflictException::sameCard();
        }

        $userId = $predecessor->ownerUserId();

        if ($successor->ownerUserId() !== $userId) {
            throw (new ModelNotFoundException)->setModel(Card::class, [$successor->getKey()]);
        }

        return DB::transaction(function () use ($predecessor, $successor, $unlockRequirement, $userId): Collection {
            $this->newCardQueuePosition()->lockOwner($userId);
            $cards = $this->lockedCards($userId, [
                (string) $predecessor->getKey(),
                (string) $successor->getKey(),
            ]);
            $livePredecessor = $cards->firstWhere('id', (string) $predecessor->getKey());
            $liveSuccessor = $cards->firstWhere('id', (string) $successor->getKey());

            if (! $livePredecessor instanceof Card || ! $liveSuccessor instanceof Card) {
                $missingId = ! $livePredecessor instanceof Card
                    ? $predecessor->getKey()
                    : $successor->getKey();

                throw (new ModelNotFoundException)->setModel(Card::class, [$missingId]);
            }

            if ($this->hasNoProgressionMetadata($livePredecessor)) {
                if (! $this->hasNoProgressionMetadata($liveSuccessor)) {
                    throw LearningPathConflictException::successorAlreadyLinked();
                }

                $groupId = strtolower((string) Str::ulid());
                $unlockedAt = now()->utc()->startOfSecond();

                $livePredecessor->variant_group_id = $groupId;
                $livePredecessor->variant_stage = 1;
                $livePredecessor->variant_status = VocabVariantStatus::Available->value;
                $livePredecessor->variant_unlocked_at = $unlockedAt;
                $livePredecessor->variant_retired_at = null;

                // Persist the successor first so a legacy predecessor without a queue
                // position is ordered after any successor that remains available.
                $this->assignSuccessorStage(
                    $liveSuccessor,
                    $groupId,
                    2,
                    $unlockRequirement,
                    collect([$livePredecessor]),
                    $userId,
                    $unlockedAt,
                );
                $this->saveAndSync($liveSuccessor, $userId);

                if (($livePredecessor->study_status ?? CardStudyStatus::New) === CardStudyStatus::New
                    && $livePredecessor->new_queue_position === null
                ) {
                    $livePredecessor->new_queue_position = $this->newCardQueuePosition()->nextForUser($userId);
                }

                $this->saveAndSync($livePredecessor, $userId);

                return $this->familyCards($userId, $groupId);
            }

            if (! $this->hasValidProgressionMetadata($livePredecessor)) {
                throw LearningPathConflictException::invalidPredecessorMetadata();
            }

            $groupId = trim((string) $livePredecessor->variant_group_id);
            $familyCards = $this->lockedFamilyCards($userId, $groupId);

            if ($familyCards->isEmpty()
                || $familyCards->contains(fn (Card $card): bool => ! $this->hasValidProgressionMetadata($card))
                || ! $familyCards->contains(fn (Card $card): bool => $card->isProgressionAvailable())
                || $this->hasMixedStageAvailability($familyCards)
                || $this->hasMixedStageUnlockRequirements($familyCards)
            ) {
                throw LearningPathConflictException::invalidFamilyMetadata();
            }

            $expectedStage = $livePredecessor->variant_stage + 1;

            // Exact retries remain successful even if the successor has since unlocked.
            if ($liveSuccessor->variant_group_id === $groupId
                && $liveSuccessor->variant_stage === $expectedStage
            ) {
                $persistedRequirement = $liveSuccessor->variant_unlock_requirement
                    ?? CardProgressionUnlockRequirement::SuccessfulRetrieval;

                if ($persistedRequirement !== $unlockRequirement) {
                    throw LearningPathConflictException::unlockRequirementMismatch();
                }

                return $this->familyCards($userId, $groupId);
            }

            if (! $this->hasNoProgressionMetadata($liveSuccessor)) {
                throw LearningPathConflictException::successorAlreadyLinked();
            }

            if ($familyCards->max('variant_stage') !== $livePredecessor->variant_stage) {
                throw LearningPathConflictException::predecessorIsNotTail();
            }

            if ($expectedStage > Card::MAX_VARIANT_STAGE) {
                throw LearningPathConflictException::stageLimitReached();
            }

            $this->assignSuccessorStage(
                $liveSuccessor,
                $groupId,
                $expectedStage,
                $unlockRequirement,
                $familyCards->where('variant_stage', $livePredecessor->variant_stage)->values(),
                $userId,
                now()->utc()->startOfSecond(),
            );
            $this->saveAndSync($liveSuccessor, $userId);

            return $this->familyCards($userId, $groupId);
        });
    }

    /** @param list<string> $cardIds @return Collection<int, Card> */
    private function lockedCards(int $userId, array $cardIds): Collection
    {
        $query = Card::query()
            ->select('cards.*')
            ->with(['deck:id,user_id,course_id'])
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->whereIn('cards.id', $cardIds);

        CardReviewCardLock::apply($query->getQuery());

        return $query->get()->values();
    }

    /** @return Collection<int, Card> */
    private function lockedFamilyCards(int $userId, string $groupId): Collection
    {
        $query = $this->familyQuery($userId, $groupId);

        CardReviewCardLock::apply($query->getQuery());

        return $query->get()->values();
    }

    /** @return Collection<int, Card> */
    private function familyCards(int $userId, string $groupId): Collection
    {
        return $this->familyQuery($userId, $groupId)
            ->orderBy('cards.variant_stage')
            ->orderByRaw('LOWER(cards.id)')
            ->orderBy('cards.id')
            ->get()
            ->values();
    }

    /** @return Builder<Card> */
    private function familyQuery(int $userId, string $groupId)
    {
        return Card::query()
            ->select('cards.*')
            ->with(['deck:id,user_id,course_id'])
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->where('cards.variant_group_id', $groupId);
    }

    private function hasNoProgressionMetadata(Card $card): bool
    {
        return ($card->variant_group_id === null || trim((string) $card->variant_group_id) === '')
            && $card->variant_sentence_id === null
            && $card->variant_kind === null
            && $card->variant_stage === null
            && $card->variant_status === null
            && $card->variant_unlock_requirement === null
            && $card->variant_unlocked_at === null;
    }

    private function hasValidProgressionMetadata(Card $card): bool
    {
        return is_string($card->variant_group_id)
            && trim($card->variant_group_id) !== ''
            && is_int($card->variant_stage)
            && $card->variant_stage > 0
            && in_array($card->variant_status, VocabVariantStatus::values(), true);
    }

    /** @param Collection<int, Card> $cards */
    private function hasMixedStageAvailability(Collection $cards): bool
    {
        return $cards
            ->groupBy('variant_stage')
            ->contains(fn (Collection $stageCards): bool => $stageCards
                ->pluck('variant_status')
                ->unique()
                ->count() > 1);
    }

    /** @param Collection<int, Card> $cards */
    private function hasMixedStageUnlockRequirements(Collection $cards): bool
    {
        return $cards
            ->groupBy('variant_stage')
            ->contains(fn (Collection $stageCards): bool => $stageCards
                ->map(fn (Card $card): string => $card->variant_unlock_requirement?->value
                    ?? CardProgressionUnlockRequirement::SuccessfulRetrieval->value)
                ->unique()
                ->count() > 1);
    }

    /** @param Collection<int, Card> $predecessorStageCards */
    private function assignSuccessorStage(
        Card $card,
        string $groupId,
        int $stage,
        CardProgressionUnlockRequirement $unlockRequirement,
        Collection $predecessorStageCards,
        int $userId,
        \DateTimeInterface $linkedAt,
    ): void {
        $isImmediatelyAvailable = $predecessorStageCards->isNotEmpty()
            && $predecessorStageCards->every(
                fn (Card $predecessor): bool => $unlockRequirement->isSatisfiedByMastery($predecessor),
            );

        $card->variant_group_id = $groupId;
        $card->variant_stage = $stage;
        $card->variant_status = $isImmediatelyAvailable
            ? VocabVariantStatus::Available->value
            : VocabVariantStatus::Locked->value;
        $card->variant_unlock_requirement = $unlockRequirement;
        $card->variant_unlocked_at = $isImmediatelyAvailable ? $linkedAt : null;
        $card->variant_retired_at = null;
        $card->new_queue_position = $isImmediatelyAvailable
            && ($card->study_status ?? CardStudyStatus::New) === CardStudyStatus::New
                ? $card->new_queue_position ?? $this->newCardQueuePosition()->nextForUser($userId)
                : null;
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
