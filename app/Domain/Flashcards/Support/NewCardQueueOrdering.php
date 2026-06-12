<?php

namespace App\Domain\Flashcards\Support;

use Illuminate\Database\Eloquent\Builder;

final class NewCardQueueOrdering
{
    /**
     * Keep legacy null queue positions after positioned cards on every supported database.
     *
     * Callers must append the tie-breakers required by their read path after this bucket order.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function nullPositionsLast(Builder $query): Builder
    {
        return $query
            ->orderByRaw('case when cards.new_queue_position is null then 1 else 0 end')
            ->orderBy('cards.new_queue_position');
    }

    /**
     * Apply the canonical positioned-card queue order used by cursor/session reads.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function positionedCards(Builder $query): Builder
    {
        return $query
            ->whereNotNull('cards.new_queue_position')
            ->orderBy('cards.new_queue_position')
            ->orderBy('cards.id');
    }
}
