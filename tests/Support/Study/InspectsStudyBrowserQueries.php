<?php

namespace Tests\Support\Study;

use App\Domain\Flashcards\Models\Deck;
use Illuminate\Support\Collection;

trait InspectsStudyBrowserQueries
{
    private function signedInDeck(): Deck
    {
        return $this->deckFor($this->signIn());
    }

    /**
     * @param  Collection<int, array{query: string}>  $queries
     * @return Collection<int, array{query: string}>
     */
    private function cardSelectQueries(Collection $queries): Collection
    {
        return $queries->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_starts_with($sql, 'select')
                && (str_contains($sql, 'from "cards"') || str_contains($sql, 'from `cards`'));
        });
    }

    /**
     * @param  Collection<int, array{query: string}>  $cardQueries
     * @return Collection<int, array{query: string}>
     */
    private function facetSelectQueries(Collection $cardQueries): Collection
    {
        return $cardQueries->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, ' as facet')
                && str_contains($sql, ' union ');
        });
    }

    /**
     * @param  Collection<int, array{query: string}>  $cardQueries
     * @return Collection<int, array{query: string}>
     */
    private function groupSelectQueries(Collection $cardQueries): Collection
    {
        return $cardQueries->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'total_rows')
                && str_contains($sql, 'group by');
        });
    }

    /**
     * @param  Collection<int, array{query: string}>  $cardQueries
     * @return Collection<int, array{query: string}>
     */
    private function pagedCardSelectQueries(Collection $cardQueries): Collection
    {
        return $cardQueries->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'source_note_id')
                && str_contains($sql, ' in ')
                && ! str_contains($sql, 'total_rows')
                && ! str_contains($sql, ' as facet');
        });
    }
}
