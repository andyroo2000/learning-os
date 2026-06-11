<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Exceptions\UndoCardReviewEventException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Sync\CardReviewEventSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\DateTime\StrictIsoDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UndoCardReviewEventAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(CardReviewEvent $reviewEvent): Card
    {
        return DB::transaction(function () use ($reviewEvent): Card {
            $card = Card::query()
                ->whereKey($reviewEvent->card_id)
                ->lockForUpdate()
                ->first();

            if ($card === null) {
                throw UndoCardReviewEventException::cardUnavailable();
            }

            $card->load('deck');

            if ($card->deck === null) {
                throw UndoCardReviewEventException::cardUnavailable();
            }

            $reviewEvent = CardReviewEvent::query()
                ->whereKey($reviewEvent->getKey())
                ->lockForUpdate()
                ->first();

            if ($reviewEvent === null) {
                throw UndoCardReviewEventException::reviewEventUnavailable();
            }

            $reviewEvent->setRelation('card', $card);

            if ($this->hasNewerReviewEvent($reviewEvent)) {
                throw UndoCardReviewEventException::notLatest();
            }

            $snapshot = $reviewEvent->card_state_before;

            if (! is_array($snapshot)) {
                throw UndoCardReviewEventException::missingSnapshot();
            }

            // Keep this restore list in sync with CardReviewStateSnapshot::beforeReview().
            $card->study_status = $this->studyStatus($snapshot);
            $card->new_queue_position = $this->nullableInteger($snapshot, 'new_queue_position');
            $card->scheduler_state = $this->nullableArray($snapshot, 'scheduler_state');
            $card->due_at = $this->nullableTimestamp($snapshot, 'due_at');
            $card->introduced_at = $this->nullableTimestamp($snapshot, 'introduced_at');
            $card->failed_at = $this->nullableTimestamp($snapshot, 'failed_at');
            $card->last_reviewed_at = $this->nullableTimestamp($snapshot, 'last_reviewed_at');
            $card->saveOrFail();

            $userId = $card->ownerUserId();
            $deletedAt = now();

            // Capture the tombstone payload before hard-deleting the review event.
            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $userId,
                    domain: CardReviewEventSyncPayload::DOMAIN,
                    resourceType: CardReviewEventSyncPayload::RESOURCE_TYPE,
                    resourceId: $reviewEvent->id,
                    operation: SyncFeedOperation::Delete->value,
                    payload: CardReviewEventSyncPayload::fromReviewEvent($reviewEvent, $deletedAt),
                ),
            );

            $reviewEvent->delete();

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

            return $card;
        });
    }

    private function hasNewerReviewEvent(CardReviewEvent $reviewEvent): bool
    {
        return CardReviewEvent::query()
            ->where('card_id', $reviewEvent->card_id)
            ->where(function (Builder $query) use ($reviewEvent): void {
                $query
                    ->where('reviewed_at', '>', $reviewEvent->reviewed_at)
                    ->orWhere(function (Builder $query) use ($reviewEvent): void {
                        $query
                            ->where('reviewed_at', $reviewEvent->reviewed_at)
                            ->where('id', '>', $reviewEvent->id);
                    });
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function studyStatus(array $snapshot): CardStudyStatus
    {
        $studyStatus = $snapshot['study_status'] ?? null;

        if (! is_string($studyStatus)) {
            throw UndoCardReviewEventException::invalidSnapshot('study_status');
        }

        return CardStudyStatus::tryFrom($studyStatus)
            ?? throw UndoCardReviewEventException::invalidSnapshot('study_status');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function nullableInteger(array $snapshot, string $key): ?int
    {
        $value = $snapshot[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw UndoCardReviewEventException::invalidSnapshot($key);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function nullableArray(array $snapshot, string $key): ?array
    {
        $value = $snapshot[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw UndoCardReviewEventException::invalidSnapshot($key);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function nullableTimestamp(array $snapshot, string $key): ?Carbon
    {
        $value = $snapshot[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw UndoCardReviewEventException::invalidSnapshot($key);
        }

        $timestamp = StrictIsoDateTime::parseOrNull($value);

        if ($timestamp === null) {
            throw UndoCardReviewEventException::invalidSnapshot($key);
        }

        return $timestamp;
    }
}
