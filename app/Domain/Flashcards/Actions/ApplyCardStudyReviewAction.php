<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\FsrsReviewScheduler;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Carbon;

class ApplyCardStudyReviewAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    /**
     * Apply an event whose (reviewed_at, id) position was validated while its card row was locked.
     *
     * @internal Review write actions own this precondition.
     */
    public function handleChronologicalNext(Card $card, CardReviewRating $rating, Carbon $reviewedAt): bool
    {
        return $this->apply($card, $rating, $reviewedAt);
    }

    private function apply(Card $card, CardReviewRating $rating, Carbon $reviewedAt): bool
    {
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

    /**
     * Preview an event whose (reviewed_at, id) position was validated while its card row was locked.
     *
     * @return array<string, mixed>|null
     *
     * @internal Review write actions own this precondition.
     */
    public function schedulerStateAfterChronologicalNextReview(Card $card, CardReviewRating $rating, Carbon $reviewedAt): ?array
    {
        return FsrsReviewScheduler::review(
            schedulerState: $card->scheduler_state,
            studyStatus: $card->study_status ?? CardStudyStatus::New,
            rating: $rating,
            reviewedAt: $reviewedAt,
        )['schedulerState'];
    }
}
