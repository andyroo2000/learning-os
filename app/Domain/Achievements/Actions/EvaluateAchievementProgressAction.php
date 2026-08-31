<?php

namespace App\Domain\Achievements\Actions;

use Illuminate\Support\Facades\Log;

final class EvaluateAchievementProgressAction
{
    public function __construct(
        private readonly GetAchievementProgressAction $getProgress,
        private readonly ReconcileAchievementAwardsAction $reconcileAwards,
    ) {}

    /** @return array{revision: string, metricValues: array<string, int>, awards: list<array{id: string, earnedAt: string}>} */
    public function handle(int $userId): array
    {
        $startedAt = hrtime(true);
        $projectionStartedAt = hrtime(true);
        $projection = $this->getProgress->projection($userId);
        $projectionDurationMs = (hrtime(true) - $projectionStartedAt) / 1_000_000;
        $reconcileStartedAt = hrtime(true);
        $awards = $this->reconcileAwards->handle(
            $userId,
            $projection->metricValues,
            $projection->thresholdReachedAt,
        );
        $reconcileDurationMs = (hrtime(true) - $reconcileStartedAt) / 1_000_000;

        Log::info('Achievement progress evaluated.', [
            'user_id' => $userId,
            'projection_duration_ms' => round($projectionDurationMs, 2),
            'reconcile_duration_ms' => round($reconcileDurationMs, 2),
            'total_duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            'award_count' => $awards->count(),
        ]);

        return $this->getProgress->withAwards([
            'revision' => GetAchievementCatalogAction::REVISION,
            'metricValues' => $projection->metricValues,
        ], $awards);
    }
}
