<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Support\NewCardQueuePosition;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Results\ReviewCardBatchResult;
use App\Domain\Reviews\Support\AppliesLockedCardStudyReview;
use App\Domain\Reviews\Support\CardReviewCardLock;
use App\Domain\Reviews\Support\CardReviewChronology;
use App\Domain\Reviews\Support\CardReviewEventIdentity;
use App\Domain\Reviews\Support\CardReviewStateSnapshot;
use App\Domain\Reviews\Sync\CardReviewEventSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Values\ClientEventKey;
use App\Domain\Sync\Values\SyncMetadata;
use App\Support\Database\IntegrityConstraintViolation;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class ReviewCardBatchAction
{
    use AppliesLockedCardStudyReview;

    private const INSERT_SAVEPOINT = 'review_card_batch_insert';

    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
        private readonly ?AdvanceCardProgressionAfterReviewAction $advanceCardProgression = null,
        private readonly ?NewCardQueuePosition $newCardQueuePosition = null,
    ) {}

    /**
     * @param  iterable<ReviewCardData>  $items
     */
    public function handle(iterable $items): ReviewCardBatchResult
    {
        $preparedItems = collect($items)
            ->values()
            ->map(fn (ReviewCardData $data): array => $this->prepare($data));

        if ($preparedItems->isEmpty()) {
            throw new InvalidArgumentException('At least one review event is required.');
        }

        $preparedItems = $this->normalizeDuplicateSyncKeyIds($preparedItems);

        return DB::transaction(function () use ($preparedItems): ReviewCardBatchResult {
            $progressionOwnerIds = $this->lockProgressionOwners($preparedItems);
            $cardsById = $this->cardsById($preparedItems);
            $this->assertLiveProgressionOwnersWereLocked($cardsById, $progressionOwnerIds);
            $preparedItems = $this->useStoredCardIds($preparedItems, $cardsById);
            $cardsById = $cardsById->keyBy(fn (Card $card): string => (string) $card->getKey());

            $existingReviewEventsBySyncKey = $this->existingReviewEventsBySyncKeyWithOwnerContext($preparedItems);
            $existingReviewEventsById = $this->existingReviewEventsByProvidedId($preparedItems);

            $this->assertExistingReviewEventsMatchRequest(
                preparedItems: $preparedItems,
                reviewEventsBySyncKey: $existingReviewEventsBySyncKey,
                reviewEventsById: $existingReviewEventsById,
                cardsById: $cardsById,
            );

            $now = now();

            $createdItems = $this->canonicalItemsForInsert($preparedItems, $existingReviewEventsBySyncKey);

            $this->assertCreatedItemsAppendToChronology($createdItems, $cardsById);

            $rows = $createdItems
                ->map(fn (array $item): array => $this->rowForInsert($item, $now))
                ->values();

            if ($rows->isNotEmpty()) {
                DB::statement('SAVEPOINT '.self::INSERT_SAVEPOINT);

                try {
                    CardReviewEvent::query()->insert($rows->all());
                    DB::statement('RELEASE SAVEPOINT '.self::INSERT_SAVEPOINT);
                } catch (QueryException $exception) {
                    DB::statement('ROLLBACK TO SAVEPOINT '.self::INSERT_SAVEPOINT);
                    DB::statement('RELEASE SAVEPOINT '.self::INSERT_SAVEPOINT);

                    if (! IntegrityConstraintViolation::matches($exception)) {
                        throw $exception;
                    }

                    $reviewEventsBySyncKey = $this->existingReviewEventsBySyncKeyWithOwnerContext($preparedItems);

                    if ($rows->contains(fn (array $item): bool => ! $reviewEventsBySyncKey->has($item['sync_key']))) {
                        // A partial match is not a clean retry; surface the original database error.
                        throw $exception;
                    }

                    // Another writer may have inserted between the pre-check and the failed bulk insert.
                    // These fresh reads are a best-effort race re-check, not a single snapshot.
                    // Under repeatable-read isolation they may still see the original snapshot; the
                    // partial-match guard above then surfaces the database error for a client retry.
                    // Keep the original card snapshot; it is the ownership context for this rolled-back write attempt.
                    $this->assertExistingReviewEventsMatchRequest(
                        preparedItems: $preparedItems,
                        reviewEventsBySyncKey: $reviewEventsBySyncKey,
                        reviewEventsById: $this->existingReviewEventsByProvidedId($preparedItems),
                        cardsById: $cardsById,
                    );

                    // The unique constraint failed a single atomic insert; all items already exist.
                    return ReviewCardBatchResult::withoutCreatedEvents(
                        $this->reviewEventsForPreparedItems($preparedItems, $reviewEventsBySyncKey),
                    );
                }
            }

            $reviewEventsBySyncKey = $this->existingReviewEventsBySyncKeyForResponse($preparedItems);
            $reviewEvents = $this->reviewEventsForPreparedItems($preparedItems, $reviewEventsBySyncKey);

            if ($createdItems->isNotEmpty()) {
                $createdReviewEvents = $this->createdReviewEventsForItems($createdItems, $reviewEventsBySyncKey);

                $this->recordAndApplyCreatedReviewEvents($createdReviewEvents, $cardsById, $progressionOwnerIds);
            }

            return $rows->isNotEmpty()
                ? ReviewCardBatchResult::withCreatedEvents($reviewEvents)
                : ReviewCardBatchResult::withoutCreatedEvents($reviewEvents);
        });
    }

    /**
     * @return array{
     *     id: string,
     *     card_id: string,
     *     rating: CardReviewRating,
     *     reviewed_at: Carbon,
     *     duration_ms: int|null,
     *     client_event_id: string,
     *     device_id: string,
     *     client_created_at: Carbon,
     *     sync_key: string,
     *     client_supplied_id: bool,
     *     identity: CardReviewEventIdentity
     * }
     */
    private function prepare(ReviewCardData $data): array
    {
        if (! Str::isUlid($data->cardId)) {
            throw new InvalidArgumentException('Card ID must be a valid ULID.');
        }

        if ($data->rating === '') {
            throw new InvalidArgumentException('Review rating is required.');
        }

        $rating = CardReviewRating::tryFrom($data->rating);

        if ($rating === null) {
            throw new InvalidArgumentException('Review rating must be one of: '.implode(', ', CardReviewRating::values()).'.');
        }

        if ($data->id !== null && ! Str::isUlid($data->id)) {
            throw new InvalidArgumentException('Review event ID must be a valid ULID.');
        }

        $syncMetadata = SyncMetadata::fromRequired(
            clientEventId: $data->clientEventId,
            deviceId: $data->deviceId,
            clientCreatedAt: $data->clientCreatedAt,
            message: 'Client event ID, device ID, and client created at are required for batch review events.',
        );

        return [
            'id' => $data->id === null ? strtolower((string) Str::ulid()) : CanonicalUlid::normalize($data->id),
            'card_id' => $data->cardId,
            'rating' => $rating,
            'reviewed_at' => $data->reviewedAt,
            'duration_ms' => $data->durationMs,
            'client_event_id' => $syncMetadata->clientEventId,
            'device_id' => $syncMetadata->deviceId,
            'client_created_at' => $syncMetadata->clientCreatedAt,
            'sync_key' => $syncMetadata->lookupKey(),
            'client_supplied_id' => $data->id !== null,
            'identity' => CardReviewEventIdentity::fromReviewCardData($data, $rating),
        ];
    }

    /**
     * @param  Collection<int, array{id: string, sync_key: string, client_supplied_id: bool}>  $preparedItems
     * @return Collection<int, array{id: string, sync_key: string, client_supplied_id: bool}>
     */
    private function normalizeDuplicateSyncKeyIds(Collection $preparedItems): Collection
    {
        $idsBySyncKey = $preparedItems
            ->filter(fn (array $item): bool => $item['client_supplied_id'])
            ->groupBy('sync_key')
            ->map(fn (Collection $items): Collection => $items->pluck('id')->unique()->values());

        return $preparedItems
            ->map(function (array $item) use ($idsBySyncKey): array {
                $providedIds = $idsBySyncKey->get($item['sync_key']);

                if ($providedIds !== null && $providedIds->count() === 1) {
                    $item['id'] = $providedIds->first();
                    // Keep client_supplied_id as "the client sent an ID"; canonical insert selection relies on it.
                }

                return $item;
            })
            ->values();
    }

    /**
     * Keep duplicate input items for response mapping, but insert only one canonical row per sync key.
     *
     * @param  Collection<int, array{sync_key: string, client_supplied_id: bool}>  $preparedItems
     * @param  Collection<string, CardReviewEvent>  $existingReviewEventsBySyncKey
     * @return Collection<int, array{sync_key: string, client_supplied_id: bool}>
     */
    private function canonicalItemsForInsert(Collection $preparedItems, Collection $existingReviewEventsBySyncKey): Collection
    {
        return $preparedItems
            ->reject(fn (array $item): bool => $existingReviewEventsBySyncKey->has($item['sync_key']))
            ->groupBy('sync_key')
            // Equivalent duplicates may differ only by whether one supplied the canonical event ID.
            ->map(fn (Collection $items): array => $items->firstWhere('client_supplied_id', true) ?? $items->first())
            ->values();
    }

    /**
     * Caller owns the transaction so every returned card remains locked through the batch writes.
     *
     * @param  Collection<int, array{card_id: string}>  $preparedItems
     * @return Collection<string, Card>
     */
    protected function cardsById(Collection $preparedItems): Collection
    {
        $cardIds = $preparedItems
            ->pluck('card_id')
            ->unique()
            ->values();

        $candidateCardIds = $cardIds
            ->flatMap(fn (string $cardId): array => CanonicalUlid::databaseCandidates($cardId))
            ->unique()
            ->values();

        $query = Card::query()
            ->select('cards.*')
            ->addSelect([
                'owner_user_id' => Deck::query()
                    ->withTrashed()
                    ->select('user_id')
                    ->whereColumn('decks.id', 'cards.deck_id')
                    ->limit(1),
                'deck_course_id' => Deck::query()
                    ->withTrashed()
                    ->select('course_id')
                    ->whereColumn('decks.id', 'cards.deck_id')
                    ->limit(1),
            ])
            ->whereKey($candidateCardIds);

        CardReviewCardLock::apply($query->getQuery());

        $cardsById = $query
            ->get()
            ->keyBy(fn (Card $card): string => CanonicalUlid::normalize((string) $card->getKey()));

        if ($cardsById->count() !== $cardIds->count()) {
            throw new InvalidArgumentException('One or more cards do not exist.');
        }

        return $cardsById;
    }

    /**
     * @param  Collection<int, array{card_id: string}>  $preparedItems
     * @param  Collection<string, Card>  $cardsById
     * @return Collection<int, array{card_id: string}>
     */
    private function useStoredCardIds(Collection $preparedItems, Collection $cardsById): Collection
    {
        return $preparedItems->map(function (array $item) use ($cardsById): array {
            $card = $cardsById->get($item['card_id'])
                ?? throw new RuntimeException('Card missing while resolving its stored ID.');
            $item['card_id'] = (string) $card->getKey();

            return $item;
        });
    }

    /**
     * Caller must validate ownership before using these lean response rows in output.
     *
     * @param  Collection<int, array{device_id: string, client_event_id: string}>  $preparedItems
     * @return Collection<string, CardReviewEvent>
     */
    private function existingReviewEventsBySyncKeyForResponse(Collection $preparedItems): Collection
    {
        return $this->reviewEventsBySyncKeyQuery($preparedItems)
            ->select('card_review_events.*')
            ->selectRaw('cards.deck_id as card_deck_id')
            ->selectRaw('decks.course_id as card_course_id')
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->get()
            ->keyBy(fn (CardReviewEvent $reviewEvent): string => ClientEventKey::lookupKey(
                $reviewEvent->device_id,
                $reviewEvent->client_event_id,
            ));
    }

    /**
     * @param  Collection<int, array{device_id: string, client_event_id: string}>  $preparedItems
     * @return Collection<string, CardReviewEvent>
     */
    private function existingReviewEventsBySyncKeyWithOwnerContext(Collection $preparedItems): Collection
    {
        return $this->reviewEventsBySyncKeyQuery($preparedItems)
            ->with([
                'card' => fn ($query) => $query->withTrashed(),
                // ownerUserId() prefers the eager-loaded deck and avoids N+1 owner lookups.
                'card.deck' => fn ($query) => $query->withTrashed(),
            ])
            ->get()
            ->keyBy(fn (CardReviewEvent $reviewEvent): string => ClientEventKey::lookupKey(
                $reviewEvent->device_id,
                $reviewEvent->client_event_id,
            ));
    }

    private function reviewEventsBySyncKeyQuery(Collection $preparedItems): Builder
    {
        $syncPairs = $preparedItems
            ->map(fn (array $item): array => [
                'device_id' => $item['device_id'],
                'client_event_id' => $item['client_event_id'],
            ])
            ->unique(fn (array $item): string => ClientEventKey::lookupKey($item['device_id'], $item['client_event_id']))
            ->values();

        return CardReviewEvent::query()
            ->where(function (Builder $query) use ($syncPairs): void {
                $syncPairs->each(function (array $item) use ($query): void {
                    $query->orWhere(function (Builder $query) use ($item): void {
                        $query
                            ->where('device_id', $item['device_id'])
                            ->where('client_event_id', $item['client_event_id']);
                    });
                });
            });
    }

    /**
     * @param  Collection<int, array{id: string, client_supplied_id: bool}>  $preparedItems
     * @return Collection<string, CardReviewEvent>
     */
    private function existingReviewEventsByProvidedId(Collection $preparedItems): Collection
    {
        $ids = $preparedItems
            ->filter(fn (array $item): bool => $item['client_supplied_id'])
            ->pluck('id')
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $candidateIds = $ids
            ->flatMap(fn (string $id): array => CanonicalUlid::databaseCandidates($id))
            ->unique()
            ->values();

        $reviewEventsByCanonicalId = CardReviewEvent::query()
            ->with([
                'card' => fn ($query) => $query->withTrashed(),
                'card.deck' => fn ($query) => $query->withTrashed(),
            ])
            ->whereKey($candidateIds)
            ->get()
            ->groupBy(fn (CardReviewEvent $reviewEvent): string => CanonicalUlid::normalize((string) $reviewEvent->id));

        return $ids->mapWithKeys(function (string $id) use ($reviewEventsByCanonicalId): array {
            $matches = $reviewEventsByCanonicalId->get($id);

            if ($matches === null) {
                return [];
            }

            $preferred = $matches->first(
                fn (CardReviewEvent $reviewEvent): bool => (string) $reviewEvent->id === $id,
            ) ?? $matches->first();

            return [$id => $preferred];
        });
    }

    /**
     * @param  Collection<int, array{id: string, card_id: string, sync_key: string, client_supplied_id: bool}>  $preparedItems
     * @param  Collection<string, CardReviewEvent>  $reviewEventsBySyncKey
     * @param  Collection<string, CardReviewEvent>  $reviewEventsById
     * @param  Collection<string, Card>  $cardsById
     */
    private function assertExistingReviewEventsMatchRequest(
        Collection $preparedItems,
        Collection $reviewEventsBySyncKey,
        Collection $reviewEventsById,
        Collection $cardsById,
    ): void {
        $itemsBySyncKey = $preparedItems->groupBy('sync_key');

        foreach ($itemsBySyncKey as $syncKey => $items) {
            $item = $items->firstWhere('client_supplied_id', true) ?? $items->first();
            $card = $cardsById->get($item['card_id'])
                ?? throw new RuntimeException('Card missing while validating review event conflicts.');
            $identity = $item['identity'];

            foreach ($items as $duplicateItem) {
                if (! $identity->matchesRequest($duplicateItem['identity'])) {
                    throw CardReviewEventConflictException::conflict($card->ownerUserId());
                }
            }

            $existingBySyncKey = $reviewEventsBySyncKey->get($syncKey);

            if ($existingBySyncKey !== null) {
                $this->assertReviewEventBelongsToCardOwner($existingBySyncKey, $card);

                if (! $identity->matchesReviewEvent($existingBySyncKey)) {
                    throw CardReviewEventConflictException::conflict($this->ownerIdFor($existingBySyncKey));
                }
            }
        }

        $providedItemGroupsById = $preparedItems
            ->filter(fn (array $item): bool => $item['client_supplied_id'])
            ->groupBy('id');

        foreach ($providedItemGroupsById as $providedItems) {
            $item = $providedItems->first();
            $identity = $item['identity'];
            $card = $cardsById->get($item['card_id'])
                ?? throw new RuntimeException('Card missing while validating review event conflicts.');

            foreach ($providedItems as $duplicateItem) {
                if (! $identity->matchesRequest($duplicateItem['identity'])) {
                    throw CardReviewEventConflictException::conflict($card->ownerUserId());
                }
            }

            $existingById = $reviewEventsById->get($item['id']);

            if ($existingById === null) {
                continue;
            }

            $this->assertReviewEventBelongsToCardOwner($existingById, $card);

            if (! $item['identity']->matchesReviewEvent($existingById)) {
                throw CardReviewEventConflictException::conflict($this->ownerIdFor($existingById));
            }
        }
    }

    private function assertReviewEventBelongsToCardOwner(CardReviewEvent $reviewEvent, Card $card): void
    {
        $conflictingUserId = $this->ownerIdFor($reviewEvent);

        if ($conflictingUserId !== $card->ownerUserId()) {
            throw CardReviewEventConflictException::conflict($conflictingUserId);
        }
    }

    private function ownerIdFor(CardReviewEvent $reviewEvent): int
    {
        if (! $reviewEvent->relationLoaded('card')) {
            throw new LogicException('Review event card relation must be eager-loaded for conflict resolution.');
        }

        $card = $reviewEvent->card;

        if ($card === null) {
            // Soft-deleted cards are loaded with withTrashed(); null here means broken historical data.
            throw new LogicException('Review event card owner could not be resolved.');
        }

        return $card->ownerUserId();
    }

    /**
     * @param  array{
     *     id: string,
     *     card_id: string,
     *     rating: CardReviewRating,
     *     reviewed_at: Carbon,
     *     duration_ms: int|null,
     *     client_event_id: string,
     *     device_id: string,
     *     client_created_at: Carbon
     * }  $item
     * @return array<string, mixed>
     */
    private function rowForInsert(array $item, Carbon $now): array
    {
        return [
            'id' => $item['id'],
            'card_id' => $item['card_id'],
            'rating' => $item['rating']->value,
            // Bulk inserts bypass Eloquent's date casting, so preserve the same
            // millisecond contract used by single client review writes.
            'reviewed_at' => CardReviewEvent::formatClientTimestampForStorage($item['reviewed_at']),
            'duration_ms' => $item['duration_ms'],
            'client_event_id' => $item['client_event_id'],
            'device_id' => $item['device_id'],
            'client_created_at' => CardReviewEvent::formatClientTimestampForStorage($item['client_created_at']),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  Collection<int, array{sync_key: string}>  $preparedItems
     * @param  Collection<string, CardReviewEvent>  $reviewEventsBySyncKey
     * @return Collection<int, CardReviewEvent>
     */
    private function reviewEventsForPreparedItems(Collection $preparedItems, Collection $reviewEventsBySyncKey): Collection
    {
        return $preparedItems
            // prepare() guarantees sync_key; this guard catches failed insert/recovery assumptions.
            ->map(fn (array $item): CardReviewEvent => $reviewEventsBySyncKey->get($item['sync_key'])
                ?? throw new RuntimeException('Review event missing after insert or conflict recovery.'))
            ->values();
    }

    /**
     * @param  Collection<int, array{sync_key: string}>  $createdItems
     * @param  Collection<string, CardReviewEvent>  $reviewEventsBySyncKey
     * @return Collection<int, CardReviewEvent>
     */
    private function createdReviewEventsForItems(Collection $createdItems, Collection $reviewEventsBySyncKey): Collection
    {
        return $createdItems
            ->map(fn (array $item): CardReviewEvent => $reviewEventsBySyncKey->get($item['sync_key'])
                ?? throw new RuntimeException('Created review event missing after insert.'))
            ->values();
    }

    /**
     * @param  Collection<int, CardReviewEvent>  $reviewEvents
     * @param  Collection<string, Card>  $cardsById
     * @param  Collection<int, int>  $progressionOwnerIds
     */
    private function recordAndApplyCreatedReviewEvents(
        Collection $reviewEvents,
        Collection $cardsById,
        Collection $progressionOwnerIds,
    ): void {
        $reviewEvents
            ->sort(fn (CardReviewEvent $left, CardReviewEvent $right): int => CardReviewChronology::compare(
                $left->reviewed_at,
                $left->id,
                $right->reviewed_at,
                $right->id,
            ))
            ->each(function (CardReviewEvent $reviewEvent) use ($cardsById, $progressionOwnerIds): void {
                $card = $cardsById->get($reviewEvent->card_id)
                    ?? throw new RuntimeException('Card missing while recording review sync feed entry.');

                if (AdvanceCardProgressionAfterReviewAction::supports($card)) {
                    // Earlier chronological events in this batch may have unlocked or
                    // retired this card through a separate family-card model instance.
                    // The row remains locked by cardsById(); refresh only grouped cards
                    // so ordinary large batches keep their bounded query contract.
                    $card->refresh()->load('deck');
                }

                // Exact retries never enter this loop, so only newly inserted events are gated.
                if (! $card->isProgressionAvailable()) {
                    throw CardReviewEventConflictException::progressionLocked($card->ownerUserId());
                }

                $this->assignReviewSnapshots($reviewEvent, $card);
                $reviewEvent->saveOrFail();
                $reviewEvent->setRelation('card', $card);

                $this->recordSyncFeedEntry->handle(
                    RecordSyncFeedEntryData::fromInput(
                        userId: $card->ownerUserId(),
                        domain: CardReviewEventSyncPayload::DOMAIN,
                        resourceType: CardReviewEventSyncPayload::RESOURCE_TYPE,
                        resourceId: $reviewEvent->id,
                        operation: SyncFeedOperation::Create->value,
                        payload: CardReviewEventSyncPayload::fromReviewEvent($reviewEvent),
                    ),
                );

                $this->applyLockedCardStudyReview($card, $reviewEvent->rating, $reviewEvent->reviewed_at);

                if ($progressionOwnerIds->contains($card->ownerUserId())
                    && AdvanceCardProgressionAfterReviewAction::supports($card)
                ) {
                    $this->advanceCardProgression()->handle(
                        $card,
                        $reviewEvent->reviewed_at,
                        $reviewEvent->id,
                    );
                }
            });
    }

    /**
     * Lock progression owners before card rows so advancement can safely lock a whole family.
     *
     * @param  Collection<int, array{card_id: string}>  $preparedItems
     * @return Collection<int, int>
     */
    private function lockProgressionOwners(Collection $preparedItems): Collection
    {
        $candidateCardIds = $preparedItems
            ->pluck('card_id')
            ->flatMap(fn (string $cardId): array => CanonicalUlid::databaseCandidates($cardId))
            ->unique()
            ->values();

        $ownerIds = Card::query()
            ->selectSub(
                Deck::query()
                    ->withTrashed()
                    ->select('user_id')
                    ->whereColumn('decks.id', 'cards.deck_id')
                    ->limit(1),
                'owner_user_id',
            )
            ->whereKey($candidateCardIds)
            ->whereNotNull('variant_group_id')
            ->whereNotNull('variant_stage')
            ->get()
            ->map(fn (Card $card): int => $card->ownerUserId())
            ->unique()
            ->sort()
            ->values();

        // One owner lock serializes every staged family for that learner through commit,
        // before any card lock is taken. Concurrent same-owner batches therefore cannot
        // deadlock by visiting multiple families in different chronology orders; batches
        // for different owners cannot share family card rows.
        $ownerIds->each(fn (int $userId): mixed => $this->newCardQueuePosition()->lockOwner($userId));

        return $ownerIds;
    }

    /**
     * @param  Collection<string, Card>  $cardsById
     * @param  Collection<int, int>  $progressionOwnerIds
     */
    private function assertLiveProgressionOwnersWereLocked(
        Collection $cardsById,
        Collection $progressionOwnerIds,
    ): void {
        $metadataWasAddedWhileWaiting = $cardsById->contains(
            fn (Card $card): bool => AdvanceCardProgressionAfterReviewAction::supports($card)
                && ! $progressionOwnerIds->contains($card->ownerUserId()),
        );

        if ($metadataWasAddedWhileWaiting) {
            throw CardReviewEventConflictException::retryableConflict();
        }
    }

    private function advanceCardProgression(): AdvanceCardProgressionAfterReviewAction
    {
        return $this->advanceCardProgression
            ?? new AdvanceCardProgressionAfterReviewAction($this->recordSyncFeedEntry);
    }

    private function newCardQueuePosition(): NewCardQueuePosition
    {
        return $this->newCardQueuePosition ?? app(NewCardQueuePosition::class);
    }

    /**
     * @param  Collection<int, array{id: string, card_id: string, reviewed_at: Carbon}>  $createdItems
     * @param  Collection<string, Card>  $cardsById
     */
    private function assertCreatedItemsAppendToChronology(Collection $createdItems, Collection $cardsById): void
    {
        $latestReviewEvents = CardReviewChronology::latestForCards($cardsById->keys());

        foreach ($createdItems->groupBy('card_id') as $cardId => $cardItems) {
            $card = $cardsById->get($cardId)
                ?? throw new RuntimeException('Card missing while validating review chronology.');
            $latestReviewEvent = $latestReviewEvents->get($cardId);
            $latestReviewedAt = $latestReviewEvent?->reviewed_at;
            $latestReviewEventId = $latestReviewEvent?->id;
            $latestHasSyncIdentity = CardReviewChronology::hasCompleteSyncIdentity($latestReviewEvent);

            $orderedItems = $cardItems->sort(fn (array $left, array $right): int => CardReviewChronology::compare(
                $left['reviewed_at'],
                $left['id'],
                $right['reviewed_at'],
                $right['id'],
            ));

            foreach ($orderedItems as $item) {
                CardReviewChronology::assertCanAppend(
                    card: $card,
                    latestReviewedAt: $latestReviewedAt,
                    latestReviewEventId: $latestReviewEventId,
                    candidateReviewedAt: $item['reviewed_at'],
                    candidateReviewEventId: $item['id'],
                    candidateHasExplicitId: $item['client_supplied_id'],
                    // Batch writes require complete sync metadata during preparation.
                    candidateHasSyncIdentity: true,
                    latestHasSyncIdentity: $latestHasSyncIdentity,
                );

                $latestReviewedAt = $item['reviewed_at'];
                $latestReviewEventId = $item['id'];
                $latestHasSyncIdentity = true;
            }
        }
    }

    private function assignReviewSnapshots(CardReviewEvent $reviewEvent, Card $card): void
    {
        $reviewEvent->card_state_before = CardReviewStateSnapshot::beforeReview($card);
        $reviewEvent->scheduler_state_before = is_array($card->scheduler_state)
            ? $card->scheduler_state
            : null;
        $reviewEvent->scheduler_state_after = $this->schedulerStateAfterLockedCardStudyReview(
            card: $card,
            rating: $reviewEvent->rating,
            reviewedAt: $reviewEvent->reviewed_at,
        );
    }
}
