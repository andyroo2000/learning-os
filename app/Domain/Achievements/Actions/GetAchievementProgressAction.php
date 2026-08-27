<?php

namespace App\Domain\Achievements\Actions;

use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\GetBurnedCardCountAction;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Models\StudyActivitySession;
use InvalidArgumentException;

final class GetAchievementProgressAction
{
    public const STABLE_CARD_METRIC = 'cards.stability_365d.count';

    public const REVIEW_METRIC = 'reviews.count';

    public const CONVERSATION_MINUTE_METRIC = 'study.conversation.minutes';

    public function __construct(private readonly GetBurnedCardCountAction $getBurnedCardCount) {}

    /** @return array{revision: string, metricValues: array<string, int>} */
    public function handle(int $userId): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Achievement progress user ID must be positive.');
        }

        $conversationMilliseconds = (int) StudyActivitySession::query()
            ->where('user_id', $userId)
            ->where('category', StudyActivityCategory::Conversation->value)
            ->sum('duration_ms');

        return [
            'revision' => GetAchievementCatalogAction::REVISION,
            'metricValues' => [
                self::STABLE_CARD_METRIC => $this->getBurnedCardCount->handle($userId),
                self::REVIEW_METRIC => CardReviewEvent::query()
                    ->ownedByActiveCardDeck($userId)
                    ->count(),
                self::CONVERSATION_MINUTE_METRIC => intdiv($conversationMilliseconds, 60_000),
            ],
        ];
    }
}
