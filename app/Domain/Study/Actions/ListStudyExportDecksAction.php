<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Models\Deck;
use Illuminate\Database\Eloquent\Collection;

class ListStudyExportDecksAction
{
    /**
     * @return Collection<int, Deck>
     */
    public function handle(int $userId): Collection
    {
        return Deck::query()
            ->where('user_id', $userId)
            // The SoftDeletes global scope keeps deleted rows out of this current-state export.
            ->orderBy('id')
            // Unbounded by design: clients use this complete section during full export/resync.
            ->get();
    }
}
