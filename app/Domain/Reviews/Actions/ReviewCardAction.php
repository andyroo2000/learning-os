<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Results\ReviewCardResult;
use App\Domain\Reviews\Sync\CardReviewEventSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Values\SyncMetadata;
use App\Support\Database\IntegrityConstraintViolation;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class ReviewCardAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(ReviewCardData $data): ReviewCardResult
    {
        if (! Str::isUlid($data->cardId)) {
            throw new InvalidArgumentException('Card ID must be a valid ULID.');
        }

        $card = Card::query()->whereKey($data->cardId)->first();

        if ($card === null) {
            throw new InvalidArgumentException('Card does not exist.');
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

        $syncMetadata = SyncMetadata::fromNullable(
            clientEventId: $data->clientEventId,
            deviceId: $data->deviceId,
            clientCreatedAt: $data->clientCreatedAt,
        );

        if ($syncMetadata !== null) {
            $existingReviewEvent = $this->findExistingReviewEvent($syncMetadata);

            if ($existingReviewEvent !== null) {
                $this->assertExistingSyncEventMatchesRequest($existingReviewEvent, $data, $card);

                return ReviewCardResult::existing($existingReviewEvent);
            }
        }

        if ($data->id !== null) {
            // Resolve common retries before opening a transaction; the catch below covers concurrent inserts.
            $existingReviewEvent = $this->findExistingReviewEventById($data->id);

            if ($existingReviewEvent !== null) {
                return ReviewCardResult::existing($this->matchingExistingReviewEvent(
                    reviewEvent: $existingReviewEvent,
                    data: $data,
                    card: $card,
                    rating: $rating,
                ));
            }
        }

        $reviewEvent = new CardReviewEvent([
            'card_id' => $data->cardId,
            'rating' => $rating,
            'reviewed_at' => $data->reviewedAt,
            'client_event_id' => $data->clientEventId,
            'device_id' => $data->deviceId,
            'client_created_at' => $data->clientCreatedAt,
        ]);

        if ($data->id !== null) {
            $reviewEvent->id = $data->id;
        }

        try {
            return DB::transaction(function () use ($card, $reviewEvent): ReviewCardResult {
                $this->saveReviewEvent($reviewEvent);

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

                return ReviewCardResult::created($reviewEvent);
            });
        } catch (QueryException $exception) {
            if ($data->id !== null && IntegrityConstraintViolation::matchesPrimaryKey($exception, 'card_review_events')) {
                $existingReviewEvent = $this->findExistingReviewEventById($data->id);

                if ($existingReviewEvent !== null) {
                    return ReviewCardResult::existing($this->matchingExistingReviewEvent(
                        reviewEvent: $existingReviewEvent,
                        data: $data,
                        card: $card,
                        rating: $rating,
                    ));
                }

                // The race winner disappeared before recovery could map it; ask the client to retry.
                Log::warning('Review event race recovery failed after primary key collision.', [
                    'review_event_id' => $data->id,
                ]);

                throw CardReviewEventConflictException::retryableConflict();
            }

            if ($syncMetadata === null || ! IntegrityConstraintViolation::matches($exception)) {
                throw $exception;
            }

            $existingReviewEvent = $this->findExistingReviewEvent($syncMetadata);

            if ($existingReviewEvent === null) {
                throw $exception;
            }

            return ReviewCardResult::existing($existingReviewEvent);
        }
    }

    private function findExistingReviewEvent(SyncMetadata $syncMetadata): ?CardReviewEvent
    {
        return CardReviewEvent::query()
            ->with([
                'card' => fn ($query) => $query->withTrashed(),
                'card.deck' => fn ($query) => $query->withTrashed(),
            ])
            ->where('client_event_id', $syncMetadata->clientEventId)
            ->where('device_id', $syncMetadata->deviceId)
            ->first();
    }

    protected function findExistingReviewEventById(string $id): ?CardReviewEvent
    {
        return CardReviewEvent::query()
            ->with([
                'card' => fn ($query) => $query->withTrashed(),
                'card.deck' => fn ($query) => $query->withTrashed(),
            ])
            ->find($id);
    }

    protected function saveReviewEvent(CardReviewEvent $reviewEvent): void
    {
        $reviewEvent->save();
    }

    private function matchingExistingReviewEvent(
        CardReviewEvent $reviewEvent,
        ReviewCardData $data,
        Card $card,
        CardReviewRating $rating,
    ): CardReviewEvent {
        $conflictingUserId = $this->ownerIdFor($reviewEvent);

        if (
            $conflictingUserId !== $card->ownerUserId()
            || CanonicalUlid::normalize((string) $reviewEvent->card_id) !== $data->cardId
            || $reviewEvent->rating !== $rating
            || ! $this->nullableTimestampsMatch($reviewEvent->reviewed_at, $data->reviewedAt)
            || $reviewEvent->client_event_id !== $data->clientEventId
            || $reviewEvent->device_id !== $data->deviceId
            || ! $this->nullableTimestampsMatch($reviewEvent->client_created_at, $data->clientCreatedAt)
        ) {
            throw CardReviewEventConflictException::conflict($conflictingUserId);
        }

        return $reviewEvent;
    }

    private function assertExistingSyncEventMatchesRequest(
        CardReviewEvent $reviewEvent,
        ReviewCardData $data,
        Card $card,
    ): void {
        $conflictingUserId = $this->ownerIdFor($reviewEvent);

        // Sync metadata is the authoritative dedup key here; enforce only the newer client-provided ID contract.
        if (
            $conflictingUserId !== $card->ownerUserId()
            || ($data->id !== null && CanonicalUlid::normalize((string) $reviewEvent->id) !== $data->id)
        ) {
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

    private function nullableTimestampsMatch(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return $left->equalTo($right);
    }
}
