<?php

namespace App\Domain\Achievements\Actions;

use App\Domain\Achievements\Models\AchievementAward;
use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final class GetAchievementProgressAction
{
    public const STABLE_CARD_METRIC = 'cards.stability_365d.count';

    public const REVIEW_METRIC = 'reviews.count';

    public const CONVERSATION_HOUR_METRIC = 'study.conversation.hours';

    public const LEGACY_CONVERSATION_MINUTE_METRIC = 'study.conversation.minutes';

    public const LISTENING_HOUR_METRIC = 'study.listening.hours';

    public const OLD_FRIEND_METRIC = 'reviews.old-friend.count';

    public const DOUBLE_FEATURE_METRIC = 'study.double-feature.days';

    public const ON_REPEAT_METRIC = 'study.daily-audio.repeat-days';

    public const CORRECT_RUN_METRIC = 'reviews.correct-run.longest';

    public const GURU_CARD_METRIC = 'cards.mastery.guru.ever.count';

    public const MASTER_CARD_METRIC = 'cards.mastery.master.ever.count';

    public const ENLIGHTENED_CARD_METRIC = 'cards.mastery.enlightened.ever.count';

    public const BURNED_CARD_METRIC = 'cards.mastery.burned.ever.count';

    public function __construct(private readonly CalculateAchievementMetricsAction $calculateMetrics) {}

    /** @return array{revision: string, metricValues: array<string, int>, awards: list<array{id: string, earnedAt: string}>} */
    public function handle(int $userId): array
    {
        $progress = [
            'revision' => GetAchievementCatalogAction::REVISION,
            'metricValues' => $this->metricValues($userId),
        ];

        return $this->withAwards(
            $progress,
            AchievementAward::query()
                ->where('user_id', $userId)
                ->orderByDesc('earned_at')
                ->orderByDesc('id')
                ->get(),
        );
    }

    /** @return array<string, int> */
    public function metricValues(int $userId): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Achievement progress user ID must be positive.');
        }

        $metrics = $this->calculateMetrics->handle($userId);

        return [
            ...$metrics,
            // Keep the v1 key while old clients finish their rollout. Archive is
            // now the canonical burned-card family and uses the lifetime metric.
            self::STABLE_CARD_METRIC => $metrics[self::BURNED_CARD_METRIC],
        ];
    }

    /**
     * @param  array{revision: string, metricValues: array<string, int>}  $progress
     * @param  Collection<int, AchievementAward>  $awards
     * @return array{revision: string, metricValues: array<string, int>, awards: list<array{id: string, earnedAt: string}>}
     */
    public function withAwards(array $progress, Collection $awards): array
    {
        return [
            ...$progress,
            'awards' => $awards->map(static fn (AchievementAward $award): array => [
                'id' => $award->achievement_id,
                'earnedAt' => ConvoLabTimestamp::serialize($award->earned_at),
            ])->all(),
        ];
    }
}
