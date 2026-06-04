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
                return ReviewCardResult::existing($existingReviewEvent);
            }
        }

        if ($data->id !== null) {
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
                $reviewEvent->save();

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
            ->where('client_event_id', $syncMetadata->clientEventId)
            ->where('device_id', $syncMetadata->deviceId)
            ->first();
    }

    private function findExistingReviewEventById(string $id): ?CardReviewEvent
    {
        return CardReviewEvent::query()
            ->with(['card.deck' => fn ($query) => $query->withTrashed()])
            ->find($id);
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
            || CanonicalUlid::normalize((string) $reviewEvent->card_id) !== CanonicalUlid::normalize($data->cardId)
            || $reviewEvent->rating !== $rating
            || ! $reviewEvent->reviewed_at?->equalTo($data->reviewedAt)
            || $reviewEvent->client_event_id !== $data->clientEventId
            || $reviewEvent->device_id !== $data->deviceId
            || ! $this->nullableTimestampsMatch($reviewEvent->client_created_at, $data->clientCreatedAt)
        ) {
            throw CardReviewEventConflictException::conflict($conflictingUserId);
        }

        return $reviewEvent;
    }

    private function ownerIdFor(CardReviewEvent $reviewEvent): int
    {
        if (! $reviewEvent->relationLoaded('card')) {
            throw new LogicException('Review event card relation must be eager-loaded for conflict resolution.');
        }

        $ownerId = $reviewEvent->card?->deck?->user_id;

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
