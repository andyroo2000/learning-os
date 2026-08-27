<?php

namespace App\Domain\Achievements\Actions;

final class EvaluateAchievementProgressAction
{
    public function __construct(
        private readonly GetAchievementProgressAction $getProgress,
        private readonly ReconcileAchievementAwardsAction $reconcileAwards,
    ) {}

    /** @return array{revision: string, metricValues: array<string, int>, awards: list<array{id: string, earnedAt: string}>} */
    public function handle(int $userId): array
    {
        $progress = $this->getProgress->handle($userId);
        $awards = $this->reconcileAwards->handle($userId, $progress['metricValues']);

        return $this->getProgress->withAwards($progress, $awards);
    }
}
