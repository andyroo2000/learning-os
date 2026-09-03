<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Exceptions\CardValidationException;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Results\CreateCardResult;
use App\Domain\Flashcards\Support\CardSchedulerState;
use App\Domain\Flashcards\Support\CardSearchText;
use App\Domain\Flashcards\Support\CreateCardConflictResolver;
use App\Domain\Flashcards\Support\NewCardQueuePosition;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Study\Actions\MatchLearningConceptsForCardAction;
use App\Domain\Study\Enums\LearningConceptMatchSource;
use App\Domain\Study\Models\CardIntroductionCohort;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Support\Database\IntegrityConstraintViolation;
use App\Support\Identifiers\CanonicalUlid;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class CreateCardAction
{
    /** @internal Test-only race seams; see tests/Feature/Flashcards/CreateCardActionTest.php. */
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
        private readonly ?NewCardQueuePosition $newCardQueuePosition = null,
        private readonly ?Closure $afterClientIdPrecheckMiss = null,
        private readonly ?Closure $afterClientIdUniqueConflict = null,
        private readonly ?MatchLearningConceptsForCardAction $matchLearningConcepts = null,
    ) {
        if ($this->hasRaceHook() && ! app()->runningUnitTests()) {
            throw new LogicException('Card creation race hooks may only be used in tests.');
        }
    }

    public function handle(CreateCardData $data): CreateCardResult
    {
        $this->validate($data);

        $existingResult = $this->existingCardResult($data);

        if ($existingResult !== null) {
            return $existingResult;
        }

        // Idempotent retries with an existing client ID are resolved above, even if the
        // deck was later soft-deleted. New cards still require an active, owned deck.
        // Use the same message for missing and unauthorized decks so programmatic callers
        // do not learn whether another user's deck exists.
        if (! $this->activeDeckIsOwnedByUser($data->deckId, $data->userId)) {
            throw CardValidationException::deckDoesNotExist();
        }

        return $this->createNewCard($data);
    }

    private function validate(CreateCardData $data): void
    {
        $this->validateDeckId($data->deckId);
        $this->validateContent($data);
        $this->validateCardId($data->id);
        $this->validateIntroductionCohort($data);
    }

    private function validateDeckId(string $deckId): void
    {
        if (! Str::isUlid($deckId)) {
            throw CardValidationException::invalidDeckId();
        }
    }

    private function validateContent(CreateCardData $data): void
    {
        if ($data->frontText === '') {
            throw CardValidationException::missingFrontText();
        }

        if ($data->backText === '') {
            throw CardValidationException::missingBackText();
        }
    }

    private function validateCardId(?string $cardId): void
    {
        if ($cardId !== null && ! Str::isUlid($cardId)) {
            throw CardValidationException::invalidCardId();
        }
    }

    private function validateIntroductionCohort(CreateCardData $data): void
    {
        if (! $this->introductionCohortBelongsToOwner($data)) {
            throw new LogicException('Card introduction cohort must belong to the card owner.');
        }
    }

    private function introductionCohortBelongsToOwner(CreateCardData $data): bool
    {
        if ($data->introductionCohortId === null) {
            return true;
        }

        if (! Str::isUlid($data->introductionCohortId)) {
            return false;
        }

        return CardIntroductionCohort::query()
            ->whereKey($data->introductionCohortId)
            ->where('user_id', $data->userId)
            ->exists();
    }

    private function existingCardResult(CreateCardData $data): ?CreateCardResult
    {
        if ($data->id === null) {
            return null;
        }

        // Prefer a clear idempotent replay response over relying only on database
        // exceptions; the save catch below still covers concurrent inserts.
        $existingCard = $this->findExistingCard($data->id);

        if ($existingCard !== null) {
            $this->validateReplayDeckAccess($existingCard, $data);

            // When deck IDs match, resolveExistingCard enforces ownership via ownerIdFor
            // before comparing metadata or returning card data.
            return CreateCardResult::existing($this->resolveExistingCard($existingCard, $data));
        }

        if ($this->afterClientIdPrecheckMiss !== null) {
            ($this->afterClientIdPrecheckMiss)($data);
        }

        return null;
    }

    private function validateReplayDeckAccess(Card $existingCard, CreateCardData $data): void
    {
        $requestedDeckDiffers = CanonicalUlid::normalize((string) $existingCard->deck_id) !== $data->deckId;

        // If the requested deck differs and is not owned by this user, fail as deck
        // validation so the response does not reveal whether the card ID matched.
        if ($requestedDeckDiffers && ! $this->activeDeckIsOwnedByUser($data->deckId, $data->userId)) {
            throw CardValidationException::deckDoesNotExist();
        }
    }

    /**
     * Uses manual transaction control so unique-key race recovery can roll back the failed
     * insert before refetching the winning card without emitting a duplicate feed entry.
     */
    private function createNewCard(CreateCardData $data): CreateCardResult
    {
        $card = $this->newCard($data);

        DB::beginTransaction();

        try {
            $this->prepareCardForInsert($card, $data);
            $this->persistCard($card, $data);
        } catch (QueryException $exception) {
            DB::rollBack();

            return $this->recoverUniqueConflict($exception, $data);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        DB::commit();

        return CreateCardResult::created($card);
    }

    private function newCard(CreateCardData $data): Card
    {
        $card = new Card([
            'deck_id' => $data->deckId,
            'front_text' => $data->frontText,
            'back_text' => $data->backText,
            'card_type' => $data->cardType,
            'prompt_json' => $data->promptJson,
            'answer_json' => $data->answerJson,
        ]);
        $card->variant_group_id = $data->variantGroupId;
        $card->variant_sentence_id = $data->variantSentenceId;
        $card->variant_kind = $data->variantKind?->value;
        $card->variant_stage = $data->variantStage;
        $card->variant_status = $data->variantStatus?->value;
        $card->variant_unlocked_at = $data->variantUnlockedAt;
        $card->introduction_cohort_id = $data->introductionCohortId;
        $card->selection_policy = $data->selectionPolicy;
        $card->priority_until = $data->priorityUntil;
        $card->search_text = CardSearchText::fromContent(
            frontText: $data->frontText,
            backText: $data->backText,
            promptJson: $data->promptJson,
            answerJson: $data->answerJson,
        );

        if ($data->id !== null) {
            $card->id = $data->id;
        }

        return $card;
    }

    private function prepareCardForInsert(Card $card, CreateCardData $data): void
    {
        if ($data->variantStatus === VocabVariantStatus::Locked) {
            if ($this->hasVariantGroup($data)) {
                $this->newCardQueuePosition()->lockOwner($data->userId);
            }
            $card->new_queue_position = null;
        } else {
            $card->new_queue_position = $this->newCardQueuePosition()->nextForUser($data->userId);
        }

        // Preserve the existing queue-owner -> Deck lock order. Revalidate after the
        // preflight read so creation cannot escape a concurrent deck/course cascade.
        $ownedDeck = Deck::query()
            ->whereKey($data->deckId)
            ->where('user_id', $data->userId)
            ->lockForUpdate()
            ->first();

        if ($ownedDeck === null) {
            throw CardValidationException::deckDoesNotExist();
        }

        $card->scheduler_state = CardSchedulerState::freshNew();
    }

    private function persistCard(Card $card, CreateCardData $data): void
    {
        $card->save();
        $this->recordSyncFeedEntry->handle(
            RecordSyncFeedEntryData::fromInput(
                userId: $data->userId,
                domain: CardSyncPayload::DOMAIN,
                resourceType: CardSyncPayload::RESOURCE_TYPE,
                resourceId: $card->id,
                operation: SyncFeedOperation::Create->value,
                payload: CardSyncPayload::fromCard($card),
            ),
        );
        DB::afterCommit(fn () => $this->matchLearningConceptsBestEffort($card));
    }

    private function recoverUniqueConflict(QueryException $exception, CreateCardData $data): CreateCardResult
    {
        if ($data->id === null || ! IntegrityConstraintViolation::matchesPrimaryKey($exception, 'cards')) {
            throw $exception;
        }

        // Covers a retry race where another request inserts this client-generated ULID
        // between the pre-check above and this save attempt.
        if ($this->afterClientIdUniqueConflict !== null) {
            ($this->afterClientIdUniqueConflict)($data, $exception);
        }

        $existingCard = $this->findExistingCard($data->id);

        if ($existingCard === null) {
            throw $exception;
        }

        // Race recovery delegates ownership hiding to resolveExistingCard; the HTTP
        // layer converts cross-user conflicts to 404 even when deck validation was skipped.
        // The race winner owns the feed entry; this loser returns existing without duplicating it.
        return CreateCardResult::existing($this->resolveExistingCard($existingCard, $data));
    }

    private function hasRaceHook(): bool
    {
        return $this->afterClientIdPrecheckMiss !== null || $this->afterClientIdUniqueConflict !== null;
    }

    private function hasVariantGroup(CreateCardData $data): bool
    {
        return is_string($data->variantGroupId) && trim($data->variantGroupId) !== '';
    }

    private function newCardQueuePosition(): NewCardQueuePosition
    {
        return $this->newCardQueuePosition ?? app(NewCardQueuePosition::class);
    }

    private function learningConceptMatcher(): MatchLearningConceptsForCardAction
    {
        return $this->matchLearningConcepts ?? app(MatchLearningConceptsForCardAction::class);
    }

    private function matchLearningConceptsBestEffort(Card $card): void
    {
        try {
            $this->learningConceptMatcher()->handle($card, LearningConceptMatchSource::Creation);
        } catch (Throwable $exception) {
            // Concept analytics must never prevent the primary card-creation workflow.
            // The resumable backfill can repair a missed best-effort match later.
            Log::warning('Learning concept matching failed after card creation.', [
                'card_id' => $card->getKey(),
                'exception' => $exception,
            ]);
        }
    }

    private function findExistingCard(string $id): ?Card
    {
        return Card::with([
            'deck' => fn ($query) => $query->withTrashed(),
        ])->withTrashed()->find($id);
    }

    private function activeDeckIsOwnedByUser(string $deckId, int $userId): bool
    {
        // SoftDeletes global scope keeps "active" limited to non-deleted decks.
        return Deck::query()
            ->whereKey($deckId)
            ->where('user_id', $userId)
            ->exists();
    }

    private function resolveExistingCard(Card $card, CreateCardData $data): Card
    {
        return (new CreateCardConflictResolver)->resolve($card, $data);
    }

    private function ownerIdFor(Card $card): int
    {
        return (new CreateCardConflictResolver)->ownerIdFor($card);
    }
}
