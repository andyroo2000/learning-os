<?php

namespace App\Domain\Achievements\Actions;

use App\Domain\Achievements\Models\AchievementAward;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\GetBurnedCardCountAction;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Models\StudyActivitySession;
use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final class GetAchievementProgressAction
{
    public const STABLE_CARD_METRIC = 'cards.stability_365d.count';

    public const REVIEW_METRIC = 'reviews.count';

    public const CONVERSATION_HOUR_METRIC = 'study.conversation.hours';

    public function __construct(private readonly GetBurnedCardCountAction $getBurnedCardCount) {}

    /** @return array{revision: string, metricValues: array<string, int>, awards: list<array{id: string, earnedAt: string}>} */
    public function handle(int $userId): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Achievement progress user ID must be positive.');
        }

        $conversationMilliseconds = (int) StudyActivitySession::query()
            ->where('user_id', $userId)
            ->where('category', StudyActivityCategory::Conversation->value)
            ->sum('duration_ms');

        $progress = [
            'revision' => GetAchievementCatalogAction::REVISION,
            'metricValues' => [
                self::STABLE_CARD_METRIC => $this->getBurnedCardCount->handle($userId),
                self::REVIEW_METRIC => CardReviewEvent::query()
                    ->ownedByActiveCardDeck($userId)
                    ->count(),
                self::CONVERSATION_HOUR_METRIC => intdiv($conversationMilliseconds, 3_600_000),
            ],
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
