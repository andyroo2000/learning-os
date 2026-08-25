<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudyMilestone;
use Illuminate\Database\Eloquent\Collection;

final class EvaluateStudyMilestonesAction
{
    public function __construct(
        private readonly GetBurnedCardCountAction $getBurnedCardCount,
        private readonly ReconcileStudyMilestonesAction $reconcileStudyMilestones,
    ) {}

    /** @return Collection<int, StudyMilestone> */
    public function handle(int $userId): Collection
    {
        return $this->reconcileStudyMilestones->handle(
            $userId,
            $this->getBurnedCardCount->handle($userId),
        );
    }
}
