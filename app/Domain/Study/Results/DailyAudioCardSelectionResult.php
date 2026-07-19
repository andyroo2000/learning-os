<?php

namespace App\Domain\Study\Results;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Support\Collection;

final readonly class DailyAudioCardSelectionResult
{
    /**
     * @param  Collection<int, Card>  $cards
     * @param  array{
     *     totalCandidates: int,
     *     totalEligible: int,
     *     selectedCount: int,
     *     dueCount: int,
     *     learningCount: int,
     *     recentMissCount: int
     * }  $summary
     */
    public function __construct(
        public Collection $cards,
        public array $summary,
    ) {}

    /**
     * @return list<string>
     */
    public function clientCardIds(): array
    {
        return $this->cards
            ->map(fn (Card $card): string => $card->clientId())
            ->values()
            ->all();
    }
}
