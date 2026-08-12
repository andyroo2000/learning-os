<?php

namespace App\Domain\Reviews\Support;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\FsrsReviewScheduler;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Keeps study-state mutation private to review writers that lock the card row first.
 *
 * Consumers must expose a RecordSyncFeedEntryAction as $recordSyncFeedEntry.
 */
trait AppliesLockedCardStudyReview
{
    private function applyLockedCardStudyReview(Card $card, CardReviewRating $rating, Carbon $reviewedAt): bool
    {
        $this->assertLockedCardStudyReviewTransaction($card);

        $currentStatus = $card->study_status ?? CardStudyStatus::New;

        if ($currentStatus === CardStudyStatus::New && $card->introduced_at === null) {
            $card->introduced_at = $reviewedAt;
        }

        $schedule = FsrsReviewScheduler::review(
            schedulerState: $card->scheduler_state,
            studyStatus: $currentStatus,
            rating: $rating,
            reviewedAt: $reviewedAt,
        );

        $card->study_status = $schedule['studyStatus'];
        $card->new_queue_position = null;
        $card->due_at = $schedule['dueAt'];
        $card->failed_at = $rating === CardReviewRating::Again ? $reviewedAt : null;
        $card->last_reviewed_at = $reviewedAt;
        $card->scheduler_state = $schedule['schedulerState'];

        if (! $card->isDirty([
            'study_status',
            'new_queue_position',
            'scheduler_state',
            'due_at',
            'introduced_at',
            'failed_at',
            'last_reviewed_at',
        ])) {
            return false;
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

        return true;
    }

    /** @return array<string, mixed>|null */
    private function schedulerStateAfterLockedCardStudyReview(Card $card, CardReviewRating $rating, Carbon $reviewedAt): ?array
    {
        $this->assertLockedCardStudyReviewTransaction($card);

        return FsrsReviewScheduler::review(
            schedulerState: $card->scheduler_state,
            studyStatus: $card->study_status ?? CardStudyStatus::New,
            rating: $rating,
            reviewedAt: $reviewedAt,
        )['schedulerState'];
    }

    private function assertLockedCardStudyReviewTransaction(Card $card): void
    {
        if ($card->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Card study review mutation requires a locked card row inside a transaction.');
        }
    }
}
