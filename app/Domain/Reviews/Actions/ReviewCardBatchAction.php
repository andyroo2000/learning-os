<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Sync\Values\ClientEventKey;
use App\Support\Database\IntegrityConstraintViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ReviewCardBatchAction
{
    private const INSERT_SAVEPOINT = 'review_card_batch_insert';

    /**
     * @param  iterable<ReviewCardData>  $items
     * @return Collection<int, CardReviewEvent>
     */
    public function handle(iterable $items): Collection
    {
        $preparedItems = collect($items)
            ->values()
            ->map(fn (ReviewCardData $data): array => $this->prepare($data));

        if ($preparedItems->isEmpty()) {
            throw new InvalidArgumentException('At least one review event is required.');
        }

        $this->ensureCardsExist($preparedItems);

        return DB::transaction(function () use ($preparedItems): Collection {
            $existingReviewEventsBySyncKey = $this->existingReviewEventsBySyncKey($preparedItems);
            $now = now();

            $rows = $preparedItems
                ->reject(fn (array $item): bool => $existingReviewEventsBySyncKey->has($item['sync_key']))
                ->unique('sync_key')
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

                    $reviewEventsBySyncKey = $this->existingReviewEventsBySyncKey($preparedItems);

                    if ($rows->contains(fn (array $item): bool => ! $reviewEventsBySyncKey->has($item['sync_key']))) {
                        // A partial match is not a clean retry; surface the original database error.
                        throw $exception;
                    }

                    return $this->reviewEventsForPreparedItems($preparedItems, $reviewEventsBySyncKey);
                }
            }

            $reviewEventsBySyncKey = $this->existingReviewEventsBySyncKey($preparedItems);

            return $this->reviewEventsForPreparedItems($preparedItems, $reviewEventsBySyncKey);
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
     *     sync_key: string
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

        if ($data->clientEventId === null || $data->deviceId === null || $data->clientCreatedAt === null) {
            throw new InvalidArgumentException('Client event ID, device ID, and client created at are required for batch review events.');
        }

        return [
            'id' => $data->id ?? strtolower((string) Str::ulid()),
            'card_id' => $data->cardId,
            'rating' => $rating,
            'reviewed_at' => $data->reviewedAt,
            'client_event_id' => $data->clientEventId,
            'device_id' => $data->deviceId,
            'client_created_at' => $data->clientCreatedAt,
            'sync_key' => $this->syncKey($data->deviceId, $data->clientEventId),
        ];
    }

    /**
     * @param  Collection<int, array{card_id: string}>  $preparedItems
     */
    private function ensureCardsExist(Collection $preparedItems): void
    {
        $cardIds = $preparedItems
            ->pluck('card_id')
            ->unique()
            ->values();

        $existingCardIds = Card::query()
            ->whereKey($cardIds)
            ->pluck('id');

        if ($existingCardIds->count() !== $cardIds->count()) {
            throw new InvalidArgumentException('One or more cards do not exist.');
        }
    }

    /**
     * @param  Collection<int, array{device_id: string, client_event_id: string}>  $preparedItems
     * @return Collection<string, CardReviewEvent>
     */
    private function existingReviewEventsBySyncKey(Collection $preparedItems): Collection
    {
        return CardReviewEvent::query()
            ->whereIn('device_id', $preparedItems->pluck('device_id')->unique())
            ->whereIn('client_event_id', $preparedItems->pluck('client_event_id')->unique())
            ->get()
            ->keyBy(fn (CardReviewEvent $reviewEvent): string => $this->syncKey(
                $reviewEvent->device_id,
                $reviewEvent->client_event_id,
            ));
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

    private function syncKey(string $deviceId, string $clientEventId): string
    {
        return ClientEventKey::fromParts($deviceId, $clientEventId)->toLookupKey();
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
}
