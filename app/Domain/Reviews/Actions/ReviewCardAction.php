<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Results\ReviewCardResult;
use App\Domain\Reviews\Support\AppliesLockedCardStudyReview;
use App\Domain\Reviews\Support\CardReviewCardLock;
use App\Domain\Reviews\Support\CardReviewChronology;
use App\Domain\Reviews\Support\CardReviewEventIdentity;
use App\Domain\Reviews\Support\CardReviewStateSnapshot;
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
    use AppliesLockedCardStudyReview;

    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(ReviewCardData $data): ReviewCardResult
    {
        if (! Str::isUlid($data->cardId)) {
            throw new InvalidArgumentException('Card ID must be a valid ULID.');
        }

        $card = $this->findCard($data->cardId);

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

        $identity = CardReviewEventIdentity::fromReviewCardData($data, $rating);

        $syncMetadata = SyncMetadata::fromNullable(
            clientEventId: $data->clientEventId,
            deviceId: $data->deviceId,
            clientCreatedAt: $data->clientCreatedAt,
        );

        if ($syncMetadata !== null) {
            $existingReviewEvent = $this->findExistingReviewEvent($syncMetadata);

            if ($existingReviewEvent !== null) {
                return ReviewCardResult::existing($this->matchingExistingReviewEvent(
                    reviewEvent: $existingReviewEvent,
                    identity: $identity,
                    card: $card,
                ));
            }
        }

        if ($data->id !== null) {
            // Resolve common retries before opening a transaction; the catch below covers concurrent inserts.
            $existingReviewEvent = $this->findExistingReviewEventById($data->id);

            if ($existingReviewEvent !== null) {
                return ReviewCardResult::existing($this->matchingExistingReviewEvent(
                    reviewEvent: $existingReviewEvent,
                    identity: $identity,
                    card: $card,
                ));
            }
        }

        try {
            return DB::transaction(function () use ($card, $data, $identity, $rating, $syncMetadata): ReviewCardResult {
                $lockedCard = $this->findCardForUpdate((string) $card->getKey());

                if ($lockedCard === null) {
                    throw new InvalidArgumentException('Card does not exist.');
                }

                // A transport retry may have committed while this request waited for the card lock.
                if ($syncMetadata !== null) {
                    $existingReviewEvent = $this->findExistingReviewEvent($syncMetadata);

                    if ($existingReviewEvent !== null) {
                        return ReviewCardResult::existing($this->matchingExistingReviewEvent(
                            reviewEvent: $existingReviewEvent,
                            identity: $identity,
                            card: $lockedCard,
                        ));
                    }
                }

                if ($data->id !== null) {
                    $existingReviewEvent = $this->findExistingReviewEventById($data->id);

                    if ($existingReviewEvent !== null) {
                        return ReviewCardResult::existing($this->matchingExistingReviewEvent(
                            reviewEvent: $existingReviewEvent,
                            identity: $identity,
                            card: $lockedCard,
                        ));
                    }
                }

                $reviewEventId = $data->id === null
                    ? strtolower((string) Str::ulid())
                    : CanonicalUlid::normalize($data->id);
                $latestReviewEvent = CardReviewChronology::latestForCards([(string) $lockedCard->getKey()])
                    ->get((string) $lockedCard->getKey());

                CardReviewChronology::assertCanAppend(
                    card: $lockedCard,
                    latestReviewedAt: $latestReviewEvent?->reviewed_at,
                    latestReviewEventId: $latestReviewEvent?->id,
                    candidateReviewedAt: $data->reviewedAt,
                    candidateReviewEventId: $reviewEventId,
                    candidateHasExplicitId: $data->id !== null,
                    candidateHasSyncIdentity: $syncMetadata !== null,
                    latestHasSyncIdentity: CardReviewChronology::hasCompleteSyncIdentity($latestReviewEvent),
                );

                $reviewEvent = new CardReviewEvent([
                    'card_id' => (string) $lockedCard->getKey(),
                    'rating' => $rating,
                    'duration_ms' => $data->durationMs,
                    'client_event_id' => $data->clientEventId,
                    'device_id' => $data->deviceId,
                ]);
                $reviewEvent->setClientTimestamps($data->reviewedAt, $data->clientCreatedAt);
                $reviewEvent->id = $reviewEventId;

                $this->assignReviewSnapshots($reviewEvent, $lockedCard, $rating);
                $this->saveReviewEvent($reviewEvent);
                $reviewEvent->setRelation('card', $lockedCard);

                $this->recordSyncFeedEntry->handle(
                    RecordSyncFeedEntryData::fromInput(
                        userId: $lockedCard->ownerUserId(),
                        domain: CardReviewEventSyncPayload::DOMAIN,
                        resourceType: CardReviewEventSyncPayload::RESOURCE_TYPE,
                        resourceId: $reviewEvent->id,
                        operation: SyncFeedOperation::Create->value,
                        payload: CardReviewEventSyncPayload::fromReviewEvent($reviewEvent),
                    ),
                );

                $this->applyLockedCardStudyReview($lockedCard, $rating, $reviewEvent->reviewed_at);

                return ReviewCardResult::created($reviewEvent);
            });
        } catch (QueryException $exception) {
            if ($data->id !== null && IntegrityConstraintViolation::matchesPrimaryKey($exception, 'card_review_events')) {
                $existingReviewEvent = $this->findExistingReviewEventById($data->id);

                if ($existingReviewEvent !== null) {
                    return ReviewCardResult::existing($this->matchingExistingReviewEvent(
                        reviewEvent: $existingReviewEvent,
                        identity: $identity,
                        card: $card,
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

            return ReviewCardResult::existing($this->matchingExistingReviewEvent(
                reviewEvent: $existingReviewEvent,
                identity: $identity,
                card: $card,
            ));
        }
    }

    private function findCard(string $cardId): ?Card
    {
        foreach (CanonicalUlid::databaseCandidates($cardId) as $candidate) {
            $card = Card::query()
                ->with('deck')
                ->whereKey($candidate)
                ->first();

            if ($card !== null) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Caller owns the transaction that keeps this row lock through review-event and card-state writes.
     */
    protected function findCardForUpdate(string $cardId): ?Card
    {
        $query = Card::query()
            ->with('deck')
            ->whereKey(CanonicalUlid::databaseCandidates($cardId));

        CardReviewCardLock::apply($query->getQuery());

        return $query->first();
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
        foreach (CanonicalUlid::databaseCandidates($id) as $candidate) {
            $reviewEvent = CardReviewEvent::query()
                ->with([
                    'card' => fn ($query) => $query->withTrashed(),
                    'card.deck' => fn ($query) => $query->withTrashed(),
                ])
                ->find($candidate);

            if ($reviewEvent !== null) {
                return $reviewEvent;
            }
        }

        return null;
    }

    protected function saveReviewEvent(CardReviewEvent $reviewEvent): void
    {
        $reviewEvent->save();
    }

    private function matchingExistingReviewEvent(
        CardReviewEvent $reviewEvent,
        CardReviewEventIdentity $identity,
        Card $card,
    ): CardReviewEvent {
        $conflictingUserId = $this->ownerIdFor($reviewEvent);

        if (
            $conflictingUserId !== $card->ownerUserId()
            || ! $identity->matchesReviewEvent($reviewEvent)
        ) {
            throw CardReviewEventConflictException::conflict($conflictingUserId);
        }

        return $reviewEvent;
    }

    private function assignReviewSnapshots(CardReviewEvent $reviewEvent, Card $card, CardReviewRating $rating): void
    {
        $reviewEvent->card_state_before = CardReviewStateSnapshot::beforeReview($card);
        $reviewEvent->scheduler_state_before = is_array($card->scheduler_state)
            ? $card->scheduler_state
            : null;
        $reviewEvent->scheduler_state_after = $this->schedulerStateAfterLockedCardStudyReview(
            card: $card,
            rating: $rating,
            reviewedAt: $reviewEvent->reviewed_at,
        );
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
}
