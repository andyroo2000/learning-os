<?php

namespace App\Domain\Reviews\Support;

use Illuminate\Database\Query\Builder;
use LogicException;

final class CardReviewCardLock
{
    private function __construct() {}

    /**
     * Apply deterministic row locking to a cards query owned by an active transaction.
     */
    public static function apply(Builder $query): Builder
    {
        if ($query->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Card review row locks require an active transaction.');
        }

        return $query
            // Canonical ULIDs are lowercase, including legacy rows stored in uppercase.
            ->orderByRaw('LOWER(cards.id)')
            // Case-insensitive MySQL primary keys cannot contain case-only duplicates; this tie-break matters on case-sensitive stores.
            ->orderByRaw('CASE WHEN cards.id = LOWER(cards.id) THEN 0 ELSE 1 END')
            ->orderBy('cards.id')
            ->lockForUpdate();
    }
}
