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
            if (($card->study_status ?? CardStudyStatus::New) !== $studyStatus) {
                $card->study_status = $studyStatus;
            }

            if ($studyStatus === CardStudyStatus::New) {
                if ($card->new_queue_position === null) {
                    $card->new_queue_position = $this->newCardQueuePosition()->nextForUser($card->ownerUserId());
                }

                $card->due_at = null;
                $card->introduced_at = null;
                $card->failed_at = null;
                $card->last_reviewed_at = null;

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
