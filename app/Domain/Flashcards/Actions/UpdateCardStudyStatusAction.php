<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Results\UpdateCardResult;
use App\Domain\Flashcards\Support\CardSchedulerState;
use App\Domain\Flashcards\Support\NewCardQueuePosition;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateCardStudyStatusAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
        private readonly ?NewCardQueuePosition $newCardQueuePosition = null,
    ) {}

    public function handle(Card $card, CardStudyStatus|string $studyStatus): UpdateCardResult
    {
        $studyStatus = $this->normalizeStudyStatus($studyStatus);

        return DB::transaction(function () use ($card, $studyStatus): UpdateCardResult {
            // New-card queue writers take the owner lock before card locks. Reserve the next
            // position in that order even when the refreshed card ultimately keeps its current
            // position, preventing an inversion with queue reorder and card creation.
            $nextNewQueuePosition = $studyStatus === CardStudyStatus::New
                ? $this->newCardQueuePosition()->nextForUser($card->ownerUserId())
                : null;

            // Re-resolve the live card under the same row lock used by review and deletion so
            // stale study state cannot overwrite a review or follow a Delete tombstone.
            $lockedCard = Card::query()
                ->whereKey($card->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedCard === null) {
                throw (new ModelNotFoundException)->setModel(Card::class, [$card->getKey()]);
            }

            $card->setRawAttributes($lockedCard->getAttributes(), true);
            $card->setRelations([]);

            if (($card->study_status ?? CardStudyStatus::New) !== $studyStatus) {
                $card->study_status = $studyStatus;
            }

            if ($studyStatus === CardStudyStatus::New) {
                if (! $card->isProgressionAvailable()) {
                    $card->new_queue_position = null;
                } elseif ($card->new_queue_position === null) {
                    $card->new_queue_position = $nextNewQueuePosition;
                }

                $card->due_at = null;
                $card->introduced_at = null;
                $card->failed_at = null;
                $card->setLastReviewedAt(null);

                if ($card->isDirty([
                    'study_status',
                    'due_at',
                    'introduced_at',
                    'failed_at',
                    'last_reviewed_at',
                ]) || $card->scheduler_state === null) {
                    $card->scheduler_state = CardSchedulerState::freshNew();
                }
            } elseif ($card->new_queue_position !== null) {
                $card->new_queue_position = null;
            }

            if (
                $studyStatus !== CardStudyStatus::New
                && $card->isDirty('study_status')
                && $card->scheduler_state === null
            ) {
                $card->scheduler_state = CardSchedulerState::forStudyStatus(
                    studyStatus: $studyStatus,
                    dueAt: $card->due_at,
                );
            }

            $wasUpdated = $card->isDirty([
                'study_status',
                'new_queue_position',
                'scheduler_state',
                'due_at',
                'introduced_at',
                'failed_at',
                'last_reviewed_at',
            ]);

            if (! $wasUpdated) {
                return UpdateCardResult::unchanged($card);
            }

            $card->saveOrFail();

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $card->ownerUserId(),
                    domain: CardSyncPayload::DOMAIN,
                    resourceType: CardSyncPayload::RESOURCE_TYPE,
                    resourceId: $card->id,
                    operation: SyncFeedOperation::Update->value,
                    payload: CardSyncPayload::fromCard($card),
                ),
            );

            return UpdateCardResult::updated($card);
        });
    }

    private function normalizeStudyStatus(CardStudyStatus|string $studyStatus): CardStudyStatus
    {
        if ($studyStatus instanceof CardStudyStatus) {
            return $studyStatus;
        }

        $normalized = strtolower(trim($studyStatus));

        if ($normalized === '') {
            throw new InvalidArgumentException('Card study_status must not be blank.');
        }

        return CardStudyStatus::tryFrom($normalized)
            ?? throw new InvalidArgumentException(
                'Card study_status must be one of: '.implode(', ', CardStudyStatus::values()).'.',
            );
    }

    private function newCardQueuePosition(): NewCardQueuePosition
    {
        return $this->newCardQueuePosition ?? app(NewCardQueuePosition::class);
    }
}
