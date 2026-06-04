<?php

namespace App\Domain\Flashcards\Support;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use Illuminate\Support\Facades\DB;
use LogicException;

class NewCardQueuePosition
{
    public function nextForUser(int $userId): int
    {
        $lockedUserId = DB::table('users')
            ->where('id', $userId)
            ->lockForUpdate()
            ->value('id');

        if ($lockedUserId === null) {
            throw new LogicException('New-card queue owner could not be locked.');
        }

        $maxPosition = Card::query()
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->where('cards.study_status', CardStudyStatus::New->value)
            ->max('cards.new_queue_position');

        return ((int) $maxPosition) + 1;
    }
}
