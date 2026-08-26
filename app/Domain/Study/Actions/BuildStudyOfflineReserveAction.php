<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Study\Services\SelectNewStudyCards;
use App\Domain\Study\Support\StudyListScopeFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BuildStudyOfflineReserveAction
{
    public const RESERVE_DAYS = 5;

    public const MAX_SCHEDULED_CARDS = 1000;

    public function __construct(
        private readonly SelectNewStudyCards $selectNewStudyCards,
    ) {}

    /**
     * @return array{cards: Collection<int, Card>, generated_at: Carbon, horizon_ends_at: Carbon}
     */
    public function handle(
        int $userId,
        ?Carbon $now = null,
        ?string $deckId = null,
        ?string $courseId = null,
    ): array {
        $now ??= now();
        $horizonEndsAt = $now->copy()->addDays(self::RESERVE_DAYS);
        $courseId = StudyListScopeFilter::normalizeId($courseId, 'courseId', 'Offline reserve');
        $deckId = StudyListScopeFilter::normalizeId($deckId, 'deckId', 'Offline reserve');
        $newCardsPerDay = StudySettings::query()
            ->where('user_id', $userId)
            ->value('new_cards_per_day') ?? StudySettings::DEFAULT_NEW_CARDS_PER_DAY;

        $scheduledCards = $this->ownedCardsQuery($userId, $courseId, $deckId)
            ->whereIn('cards.study_status', [
                CardStudyStatus::Learning->value,
                CardStudyStatus::Review->value,
                CardStudyStatus::Relearning->value,
            ])
            ->where('cards.due_at', '<=', $horizonEndsAt)
            ->orderBy('cards.due_at')
            ->orderBy('cards.id')
            ->limit(self::MAX_SCHEDULED_CARDS)
            ->get();

        $newCardsQuery = $this->ownedCardsQuery($userId, $courseId, $deckId)
            ->where('cards.study_status', CardStudyStatus::New->value);
        $newCards = $this->selectNewStudyCards->handle(
            $newCardsQuery,
            $userId,
            $newCardsPerDay * self::RESERVE_DAYS,
            $now,
            availabilityThrough: $horizonEndsAt,
        );

        return [
            'cards' => $scheduledCards->concat($newCards),
            'generated_at' => $now,
            'horizon_ends_at' => $horizonEndsAt,
        ];
    }

    /**
     * @return Builder<Card>
     */
    private function ownedCardsQuery(int $userId, ?string $courseId, ?string $deckId): Builder
    {
        return Card::query()
            ->select('cards.*')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->whereProgressionAvailable()
            ->when($courseId !== null, fn ($query) => $query->where('decks.course_id', $courseId))
            ->when($deckId !== null, fn ($query) => $query->where('cards.deck_id', $deckId));
    }
}
