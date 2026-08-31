<?php

namespace App\Domain\Flashcards\Support;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class NewCardQueuePosition
{
    public function nextForUser(int $userId): int
    {
        $this->lockOwner($userId);

        $maxPosition = Card::query()
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->whereProgressionAvailable()
            ->where('cards.study_status', CardStudyStatus::New->value)
            ->max('cards.new_queue_position');

        return ((int) $maxPosition) + 1;
    }

    public function lockOwner(int $userId): void
    {
        $lockedUserId = $this->ownerLockQuery(DB::connection(), $userId)->value('id');

        if ($lockedUserId === null) {
            throw new LogicException('New-card queue owner could not be locked.');
        }
    }

    /**
     * Reuse existing positions and append deterministic synthetic positions for legacy cards.
     *
     * @param  Collection<int, Card>  $cards
     * @return list<int>
     */
    public function availableForCards(int $userId, Collection $cards): array
    {
        $nextSyntheticPosition = null;
        $positions = [];

        foreach ($cards as $card) {
            if ($card->new_queue_position !== null) {
                $positions[] = $card->new_queue_position;

                continue;
            }

            if ($nextSyntheticPosition === null) {
                $nextSyntheticPosition = $this->nextForUser($userId);
            }

            $positions[] = $nextSyntheticPosition;
            $nextSyntheticPosition++;
        }

        sort($positions);

        return $positions;
    }

    /** @internal Exposed to compile the supported database lock grammars in tests. */
    protected function ownerLockQuery(ConnectionInterface $connection, int $userId): Builder
    {
        $ownerQuery = $connection->table('users')->where('id', $userId);

        // PostgreSQL foreign-key checks take KEY SHARE on the owner row while recording
        // sync entries. NO KEY UPDATE still serializes queue writers and account deletion
        // without deadlocking a review that already owns the card row.
        return $connection->getDriverName() === 'pgsql'
            ? $ownerQuery->lock('for no key update')
            : $ownerQuery->lockForUpdate();
    }
}
