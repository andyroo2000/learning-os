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

    public function handle(Card $card, CardReviewRating $rating, Carbon $reviewedAt): bool
    {
        if (! $this->shouldApply($card, $reviewedAt)) {
            return false;
        }

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
     * @return array<string, mixed>|null
     */
    public function schedulerStateAfterReview(Card $card, CardReviewRating $rating, Carbon $reviewedAt): ?array
    {
        if (! $this->shouldApply($card, $reviewedAt)) {
            return is_array($card->scheduler_state) ? $card->scheduler_state : null;
        }

        return FsrsReviewScheduler::review(
            schedulerState: $card->scheduler_state,
            studyStatus: $card->study_status ?? CardStudyStatus::New,
            rating: $rating,
            reviewedAt: $reviewedAt,
        )['schedulerState'];
    }

    private function shouldApply(Card $card, Carbon $reviewedAt): bool
    {
        return $card->last_reviewed_at === null || $card->last_reviewed_at->lessThan($reviewedAt);
    }
}
