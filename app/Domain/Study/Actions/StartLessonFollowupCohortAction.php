<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardSourceKind;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\NewCardQueueOrdering;
use App\Domain\Study\Models\CardIntroductionCohort;
use App\Domain\Study\Results\StartStudySessionResult;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

class StartLessonFollowupCohortAction
{
    public function __construct(
        private readonly GetStudyOverviewAction $getStudyOverview,
    ) {}

    public function handle(
        int $userId,
        string $cohortId,
        ?string $timeZone = null,
        ?Carbon $now = null,
    ): StartStudySessionResult {
        $now ??= now();
        $cohort = CardIntroductionCohort::query()
            ->whereKey($cohortId)
            ->where('user_id', $userId)
            ->where('source_kind', CardSourceKind::LessonFollowup->value)
            ->first();

        if ($cohort === null) {
            throw (new ModelNotFoundException)->setModel(CardIntroductionCohort::class, [$cohortId]);
        }

        $overview = $this->getStudyOverview->handle(
            userId: $userId,
            timeZone: $timeZone,
            now: $now,
        );
        $limit = (int) $overview['lesson_batch_size'];
        $query = Card::query()
            ->select('cards.*')
            ->ownedByActiveDeck($userId)
            ->where('cards.introduction_cohort_id', $cohort->id)
            ->where('cards.study_status', CardStudyStatus::New->value)
            ->whereProgressionAvailable()
            ->where(function ($query) use ($now): void {
                $query->whereNull('cards.introduction_available_at')
                    ->orWhere('cards.introduction_available_at', '<=', $now);
            });

        return new StartStudySessionResult(
            $overview,
            NewCardQueueOrdering::positionedCards($query)->limit($limit)->get(),
        );
    }
}
