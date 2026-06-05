<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Database\Eloquent\Collection;

class ListStudyExportCardsAction
{
    /**
     * @return Collection<int, Card>
     */
    public function handle(int $userId): Collection
    {
        return Card::query()
            ->with(['deck:id,user_id,course_id'])
            ->whereHas('deck', fn ($query) => $query->where('user_id', $userId))
            // Card and Deck SoftDeletes scopes keep tombstoned rows out of this current-state export.
            ->orderBy('id')
            // Unbounded by design: clients use this complete section during full export/resync.
            ->get();
    }
}
