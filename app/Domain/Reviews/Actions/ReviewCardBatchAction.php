<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Results\ReviewCardBatchResult;
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
    private const INSERT_SAVEPOINT = 'review_card_batch_insert';

    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
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

        $preparedItems = $this->normalizeDuplicateSyncKeyProvidedIds($preparedItems);

        return DB::transaction(function () use ($preparedItems): ReviewCardBatchResult {
            $cardsById = $this->cardsById($preparedItems);

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

                $this->recordCreatedFeedEntries($createdReviewEvents, $cardsById);
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
     *     client_event_id: string,
     *     device_id: string,
     *     client_created_at: Carbon,
     *     sync_key: string,
     *     provided_id: bool
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
            'id' => $data->id ?? strtolower((string) Str::ulid()),
            'card_id' => $data->cardId,
            'rating' => $rating,
            'reviewed_at' => $data->reviewedAt,
            'client_event_id' => $syncMetadata->clientEventId,
            'device_id' => $syncMetadata->deviceId,
            'client_created_at' => $syncMetadata->clientCreatedAt,
            'sync_key' => $syncMetadata->lookupKey(),
            'provided_id' => $data->id !== null,
        ];
    }

    /**
     * @param  Collection<int, array{id: string, sync_key: string, provided_id: bool}>  $preparedItems
     * @return Collection<int, array{id: string, sync_key: string, provided_id: bool}>
     */
    private function normalizeDuplicateSyncKeyProvidedIds(Collection $preparedItems): Collection
    {
        $idsBySyncKey = $preparedItems
            ->filter(fn (array $item): bool => $item['provided_id'])
            ->groupBy('sync_key')
            ->map(fn (Collection $items): Collection => $items->pluck('id')->unique()->values());

        $duplicateIdsForSyncKey = $idsBySyncKey->first(fn (Collection $ids): bool => $ids->count() > 1);

        if ($duplicateIdsForSyncKey !== null) {
            throw new InvalidArgumentException('Batch review events with the same sync metadata must use the same review event ID.');
        }

        $syncKeyByProvidedId = $preparedItems
            ->filter(fn (array $item): bool => $item['provided_id'])
            ->groupBy('id')
            ->map(fn (Collection $items): Collection => $items->pluck('sync_key')->unique()->values());

        $duplicateSyncKeysForId = $syncKeyByProvidedId->first(fn (Collection $syncKeys): bool => $syncKeys->count() > 1);

        if ($duplicateSyncKeysForId !== null) {
            throw new InvalidArgumentException('Batch review events with the same review event ID must use the same sync metadata.');
        }

        return $preparedItems
            ->map(function (array $item) use ($idsBySyncKey): array {
                $providedIds = $idsBySyncKey->get($item['sync_key']);

                if ($providedIds !== null && $providedIds->count() === 1) {
                    $item['id'] = $providedIds->first();
                    // Keep provided_id as "the client sent an ID"; canonical insert selection relies on it.
                }

                return $item;
            })
            ->values();
    }

    /**
     * Keep duplicate input items for response mapping, but insert only one canonical row per sync key.
     *
     * @param  Collection<int, array{sync_key: string, provided_id: bool}>  $preparedItems
     * @param  Collection<string, CardReviewEvent>  $existingReviewEventsBySyncKey
     * @return Collection<int, array{sync_key: string, provided_id: bool}>
     */
    private function canonicalItemsForInsert(Collection $preparedItems, Collection $existingReviewEventsBySyncKey): Collection
    {
        return $preparedItems
            ->reject(fn (array $item): bool => $existingReviewEventsBySyncKey->has($item['sync_key']))
            ->groupBy('sync_key')
            // Sync-key duplicates describe the same client event; prefer the item that supplied the canonical ID.
            ->map(fn (Collection $items): array => $items->firstWhere('provided_id', true) ?? $items->first())
            ->values();
    }

    /**
     * @param  Collection<int, array{card_id: string}>  $preparedItems
     * @return Collection<string, Card>
     */
    private function cardsById(Collection $preparedItems): Collection
    {
        $cardIds = $preparedItems
            ->pluck('card_id')
            ->unique()
            ->values();

        $cardsById = Card::query()
            ->select('cards.*')
            ->selectRaw('decks.user_id as owner_user_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->whereKey($cardIds)
            ->get()
            ->keyBy('id');

        if ($cardsById->count() !== $cardIds->count()) {
            throw new InvalidArgumentException('One or more cards do not exist.');
        }

        return $cardsById;
    }

    /**
     * @param  Collection<int, array{device_id: string, client_event_id: string}>  $preparedItems
     * @return Collection<string, CardReviewEvent>
     */
    private function existingReviewEventsBySyncKeyForResponse(Collection $preparedItems): Collection
    {
        return $this->reviewEventsBySyncKeyQuery($preparedItems)
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
        return CardReviewEvent::query()
            ->whereIn('device_id', $preparedItems->pluck('device_id')->unique())
            ->whereIn('client_event_id', $preparedItems->pluck('client_event_id')->unique());
    }

    /**
     * @param  Collection<int, array{id: string, provided_id: bool}>  $preparedItems
     * @return Collection<string, CardReviewEvent>
     */
    private function existingReviewEventsByProvidedId(Collection $preparedItems): Collection
    {
        $ids = $preparedItems
            ->filter(fn (array $item): bool => $item['provided_id'])
            ->pluck('id')
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return CardReviewEvent::query()
            ->with([
                'card' => fn ($query) => $query->withTrashed(),
                'card.deck' => fn ($query) => $query->withTrashed(),
            ])
            ->whereKey($ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, array{id: string, card_id: string, sync_key: string, provided_id: bool}>  $preparedItems
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
            $item = $items->first();
            $card = $cardsById->get($item['card_id'])
                ?? throw new RuntimeException('Card missing while validating review event conflicts.');

            $existingBySyncKey = $reviewEventsBySyncKey->get($syncKey);

            if ($existingBySyncKey !== null) {
                $this->assertReviewEventBelongsToCardOwner($existingBySyncKey, $card);

                // Sync metadata remains the authoritative dedup key; explicit IDs must agree when present.
                $providedId = $items->firstWhere('provided_id', true)['id'] ?? null;

                if ($providedId !== null && CanonicalUlid::normalize((string) $existingBySyncKey->id) !== $providedId) {
                    throw CardReviewEventConflictException::conflict($this->ownerIdFor($existingBySyncKey));
                }
            }
        }

        $providedItemsById = $preparedItems
            ->filter(fn (array $item): bool => $item['provided_id'])
            ->unique('id');

        foreach ($providedItemsById as $item) {
            $existingById = $reviewEventsById->get($item['id']);

            if ($existingById === null) {
                continue;
            }

            $card = $cardsById->get($item['card_id'])
                ?? throw new RuntimeException('Card missing while validating review event conflicts.');

            $this->assertReviewEventBelongsToCardOwner($existingById, $card);

            if (
                $existingById->device_id === null
                || $existingById->client_event_id === null
                || ClientEventKey::lookupKey($existingById->device_id, $existingById->client_event_id) !== $item['sync_key']
            ) {
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
            throw new LogicException('Review event card owner could not be resolved.');
        }

        if (! $card->relationLoaded('deck')) {
            throw new LogicException('Review event card deck relation must be eager-loaded for conflict resolution.');
        }

        $ownerId = $card->deck?->user_id;

        if ($ownerId === null) {
            throw new LogicException('Review event card owner could not be resolved.');
        }

        return (int) $ownerId;
    }

    /**
     * @param  array{
     *     id: string,
     *     card_id: string,
     *     rating: CardReviewRating,
     *     reviewed_at: Carbon,
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
            'reviewed_at' => $item['reviewed_at'],
            'client_event_id' => $item['client_event_id'],
            'device_id' => $item['device_id'],
            'client_created_at' => $item['client_created_at'],
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
     */
    private function recordCreatedFeedEntries(Collection $reviewEvents, Collection $cardsById): void
    {
        $reviewEvents->each(function (CardReviewEvent $reviewEvent) use ($cardsById): void {
            $card = $cardsById->get($reviewEvent->card_id)
                ?? throw new RuntimeException('Card missing while recording review sync feed entry.');

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
        });
    }
}
