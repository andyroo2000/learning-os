<?php

namespace App\Domain\Achievements\Actions;

use App\Domain\Achievements\Actions\Concerns\BuildsAchievementStudyProjectionRows;
use App\Domain\Achievements\Actions\Concerns\DetectsStaleAchievementProjections;
use App\Domain\Achievements\Actions\Concerns\ManagesAchievementProjectionLifecycle;
use App\Domain\Achievements\Actions\Concerns\NormalizesAchievementProjectionValues;
use App\Domain\Achievements\Actions\Concerns\ProjectsAchievementCards;
use App\Domain\Achievements\Actions\Concerns\ProjectsAchievementReviews;
use App\Domain\Achievements\Actions\Concerns\ProjectsAchievementStudySessions;
use App\Domain\Achievements\Actions\Concerns\QueriesAchievementProjectionSources;
use App\Domain\Achievements\Actions\Concerns\RunsAchievementMetricProjection;
use App\Domain\Achievements\Actions\Concerns\SeedsAchievementReviewFacts;
use App\Domain\Achievements\Actions\Concerns\SeedsAchievementStudyFacts;
use App\Domain\Achievements\Results\AchievementMetricProjectionResult;
use App\Domain\Achievements\Support\AchievementCardProjectionUpdater;
use App\Domain\Achievements\Support\AchievementStudyMetricFacts;
use App\Domain\Achievements\Support\AchievementThresholdCrossingTracker;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class ProjectAchievementMetricsAction
{
    use BuildsAchievementStudyProjectionRows;
    use DetectsStaleAchievementProjections;
    use ManagesAchievementProjectionLifecycle;
    use NormalizesAchievementProjectionValues;
    use ProjectsAchievementCards;
    use ProjectsAchievementReviews;
    use ProjectsAchievementStudySessions;
    use QueriesAchievementProjectionSources;
    use RunsAchievementMetricProjection;
    use SeedsAchievementReviewFacts;
    use SeedsAchievementStudyFacts;

    // Bump this whenever catalog metric keys or thresholds change so existing
    // users rebuild the persisted crossing dates required for reconciliation.
    private const PROJECTION_VERSION = 1;

    public function __construct(
        private readonly CalculateAchievementMetricsAction $calculateMetrics,
        private readonly AchievementCardProjectionUpdater $cardProjectionUpdater,
        private readonly AchievementStudyMetricFacts $studyMetricFacts,
        private readonly AchievementThresholdCrossingTracker $thresholdCrossings,
    ) {}

    public function handle(int $userId): AchievementMetricProjectionResult
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Achievement progress user ID must be positive.');
        }

        $startedAt = hrtime(true);
        $mode = 'incremental';
        $counts = ['reviews' => 0, 'cards' => 0, 'studySessions' => 0];

        $projection = $this->projectMetrics($userId, $mode, $counts);

        if ($mode !== 'incremental' || array_sum($counts) > 0) {
            Log::info('Achievement metric projection updated.', [
                'user_id' => $userId,
                'mode' => $mode,
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                ...$counts,
            ]);
        }

        return new AchievementMetricProjectionResult(
            metricValues: $this->integerMetrics($projection->metric_values),
            thresholdReachedAt: $this->thresholdDates($projection->threshold_reached_at),
        );
    }
}
