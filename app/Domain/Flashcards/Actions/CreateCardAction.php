<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Exceptions\CardConflictException;
use App\Domain\Flashcards\Exceptions\CardValidationException;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Results\CreateCardResult;
use App\Domain\Flashcards\Support\NewCardQueuePosition;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
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
    ) {
        if (($afterClientIdPrecheckMiss !== null || $afterClientIdUniqueConflict !== null) && ! app()->runningUnitTests()) {
            throw new LogicException('Card creation race hooks may only be used in tests.');
        }
    }

    public function handle(CreateCardData $data): CreateCardResult
    {
        if (! Str::isUlid($data->deckId)) {
            throw CardValidationException::invalidDeckId();
        }

        if ($data->frontText === '') {
            throw CardValidationException::missingFrontText();
        }

        if ($data->backText === '') {
            throw CardValidationException::missingBackText();
        }

        if ($data->id !== null && ! Str::isUlid($data->id)) {
            throw CardValidationException::invalidCardId();
        }

        if ($data->id !== null) {
            // Prefer a clear idempotent replay response over relying only on database
            // exceptions; the save catch below still covers concurrent inserts.
            $existingCard = $this->findExistingCard($data->id);

            if ($existingCard !== null) {
                // If the requested deck differs and is not owned by this user, fail as deck
                // validation so the response does not reveal whether the card ID matched.
                if (CanonicalUlid::normalize((string) $existingCard->deck_id) !== $data->deckId
                    && ! $this->activeDeckIsOwnedByUser($data->deckId, $data->userId)
                ) {
                    throw CardValidationException::deckDoesNotExist();
                }

                // When deck IDs match, resolveExistingCard enforces ownership via ownerIdFor
                // before comparing metadata or returning card data.
                return CreateCardResult::existing($this->resolveExistingCard($existingCard, $data));
            }

            if ($this->afterClientIdPrecheckMiss !== null) {
                ($this->afterClientIdPrecheckMiss)($data);
            }
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

    /**
     * Uses manual transaction control so unique-key race recovery can roll back the failed
     * insert before refetching the winning card without emitting a duplicate feed entry.
     */
    private function createNewCard(CreateCardData $data): CreateCardResult
    {
        $card = new Card([
            'deck_id' => $data->deckId,
            'front_text' => $data->frontText,
            'back_text' => $data->backText,
        ]);

        if ($data->id !== null) {
            $card->id = $data->id;
        }

        DB::beginTransaction();

        try {
            $card->new_queue_position = $this->newCardQueuePosition()->nextForUser($data->userId);
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
        } catch (QueryException $exception) {
            DB::rollBack();

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
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        DB::commit();

        return CreateCardResult::created($card);
    }

    private function newCardQueuePosition(): NewCardQueuePosition
    {
        return $this->newCardQueuePosition ?? app(NewCardQueuePosition::class);
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
        // Resolve owner before any conflict response so cross-user tombstones can still be
        // hidden as 404. If this fails, the cards.deck_id FK invariant has already broken.
        $conflictingUserId = $this->ownerIdFor($card);

        if ($card->trashed()) {
            // Tombstones must win before metadata checks. Deleted IDs remain reserved,
            // so owners get a deletion signal and other users still get a hidden 404.
            // Cross-user tombstones still carry the deleted flag; HTTP checks ownership first.
            throw CardConflictException::cardDeleted($conflictingUserId);
        }

        if ($this->deckIsTrashed($card)) {
            throw CardConflictException::deckDeleted($conflictingUserId);
        }

        // This action stores trimmed text; trim stored values too so legacy/direct rows
        // compare by the same canonical content.
        if (
            $conflictingUserId !== $data->userId
            // Stored deck IDs should already be canonical, but normalize defensively for
            // legacy/direct rows so idempotency compares canonical IDs on both sides.
            || CanonicalUlid::normalize((string) $card->deck_id) !== $data->deckId
            || trim($card->front_text ?? '') !== $data->frontText
            || trim($card->back_text ?? '') !== $data->backText
        ) {
            throw CardConflictException::conflict($conflictingUserId);
        }

        return $card;
    }

    private function ownerIdFor(Card $card): int
    {
        // findExistingCard must eager-load deck before conflict resolution.
        // @see \Tests\Feature\Flashcards\CreateCardActionTest::test_it_fails_when_existing_card_owner_cannot_be_resolved()
        $deck = $this->deckForConflictResolution($card);

        $ownerId = $deck?->user_id;

        if ($ownerId === null) {
            // The deck relation is null despite the cards.deck_id cascade-on-delete FK.
            // Treat that as a data-integrity invariant failure and let it surface as a 500.
            Log::warning('Card conflict owner could not be resolved.', [
                'card_id' => $card->id,
                'deck_id' => $card->deck_id,
            ]);

            throw new LogicException('Card deck owner could not be resolved.');
        }

        return (int) $ownerId;
    }

    private function deckIsTrashed(Card $card): bool
    {
        return $this->deckForConflictResolution($card)?->trashed() === true;
    }

    private function deckForConflictResolution(Card $card): ?Deck
    {
        if (! $card->relationLoaded('deck')) {
            throw new LogicException('Card deck relation must be eager-loaded for conflict resolution.');
        }

        return $card->deck;
    }
}
