<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\CardSchedulerState;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Carbon;

class ApplyCardStudyReviewAction
{
    // Deterministic starter intervals keep the server-owned state useful until FSRS state is added.
    private const AGAIN_RELEARNING_MINUTES = 10;

    private const HARD_LEARNING_DAYS = 1;

    private const GOOD_REVIEW_DAYS = 3;

    private const EASY_REVIEW_DAYS = 7;

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

        $nextStudyStatus = $this->nextStudyStatus($currentStatus, $rating);
        $nextDueAt = $this->nextDueAt($rating, $reviewedAt);

        $card->study_status = $nextStudyStatus;
        $card->new_queue_position = null;
        $card->due_at = $nextDueAt;
        $card->failed_at = $rating === CardReviewRating::Again ? $reviewedAt : null;
        $card->last_reviewed_at = $reviewedAt;
        $card->scheduler_state = CardSchedulerState::reviewed(
            card: $card,
            rating: $rating,
            studyStatus: $nextStudyStatus,
            dueAt: $nextDueAt,
            reviewedAt: $reviewedAt,
        );

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

        $currentStatus = $card->study_status ?? CardStudyStatus::New;
        $nextStudyStatus = $this->nextStudyStatus($currentStatus, $rating);
        $nextDueAt = $this->nextDueAt($rating, $reviewedAt);

        return CardSchedulerState::reviewed(
            card: $card,
            rating: $rating,
            studyStatus: $nextStudyStatus,
            dueAt: $nextDueAt,
            reviewedAt: $reviewedAt,
        );
    }

    private function shouldApply(Card $card, Carbon $reviewedAt): bool
    {
        return $card->last_reviewed_at === null || $card->last_reviewed_at->lessThan($reviewedAt);
    }

    private function nextStudyStatus(CardStudyStatus $currentStatus, CardReviewRating $rating): CardStudyStatus
    {
        return match ($rating) {
            CardReviewRating::Again => CardStudyStatus::Relearning,
            CardReviewRating::Hard => in_array($currentStatus, [
                CardStudyStatus::New,
                CardStudyStatus::Learning,
                CardStudyStatus::Relearning,
            ], strict: true) ? CardStudyStatus::Learning : CardStudyStatus::Review,
            CardReviewRating::Good,
            CardReviewRating::Easy => CardStudyStatus::Review,
        };
    }

    private function nextDueAt(CardReviewRating $rating, Carbon $reviewedAt): Carbon
    {
        return match ($rating) {
            CardReviewRating::Again => $reviewedAt->copy()->addMinutes(self::AGAIN_RELEARNING_MINUTES),
            CardReviewRating::Hard => $reviewedAt->copy()->addDays(self::HARD_LEARNING_DAYS),
            CardReviewRating::Good => $reviewedAt->copy()->addDays(self::GOOD_REVIEW_DAYS),
            CardReviewRating::Easy => $reviewedAt->copy()->addDays(self::EASY_REVIEW_DAYS),
        };
    }
}
