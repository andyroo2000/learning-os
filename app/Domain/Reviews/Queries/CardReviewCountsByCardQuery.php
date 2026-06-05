<?php

namespace App\Domain\Reviews\Queries;

use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Support\Collection;

class CardReviewCountsByCardQuery
{
    /**
     * @param  Collection<int, string>  $cardIds
     * @return array<string, int>
     */
    public function handle(Collection $cardIds): array
    {
        if ($cardIds->isEmpty()) {
            return [];
        }

        return CardReviewEvent::query()
            ->whereIn('card_id', $cardIds->all())
            ->selectRaw('card_id, count(*) as review_count')
            ->groupBy('card_id')
            ->pluck('review_count', 'card_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
