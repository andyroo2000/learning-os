<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Support\NewCardQueueOrdering;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class StudyPileQueue
{
    public const UNCATEGORIZED = 'uncategorized';

    public const LEARN_SOON = 'learn-soon';

    public static function eligibilitySql(): string
    {
        return <<<'SQL'
            (cards.book_pile_id IS NULL OR EXISTS (
                SELECT 1 FROM study_piles AS source_pile
                WHERE source_pile.id = cards.book_pile_id AND source_pile.is_active = true
                AND (cards.book_chapter_id IS NULL OR EXISTS (
                    SELECT 1 FROM study_pile_chapters AS source_chapter
                    WHERE source_chapter.id = cards.book_chapter_id
                    AND source_chapter.pile_id = source_pile.id AND source_chapter.is_active = true
                ))
            ))
            SQL;
    }

    public static function ordered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN cards.book_pile_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('(SELECT position FROM study_piles WHERE id = cards.book_pile_id)')
            ->orderBy('cards.book_pile_id')
            ->orderByRaw('CASE WHEN cards.book_chapter_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('(SELECT position FROM study_pile_chapters WHERE id = cards.book_chapter_id)')
            ->orderBy('cards.book_chapter_id')
            ->orderByRaw('CASE WHEN cards.book_position IS NULL THEN 1 ELSE 0 END')
            ->orderBy('cards.book_position');
    }

    public static function select(Builder $query, array $selection): Collection
    {
        $pileId = $selection['pile_id'];
        if ($pileId === self::UNCATEGORIZED) {
            $query->whereNull('cards.book_pile_id');
        } elseif ($pileId === self::LEARN_SOON) {
            self::learnSoon($query, $selection['now']);
        } else {
            $pile = StudyPileAccess::owned($selection['user_id'], StudyPileAccess::id($pileId));
            $query->where('cards.book_pile_id', $pile->id);
        }
        $query->whereRaw(self::eligibilitySql())
            ->where(fn ($query) => $query->whereNull('cards.introduction_available_at')
                ->orWhere('cards.introduction_available_at', '<=', $selection['now']));

        return NewCardQueueOrdering::positionedCards(self::ordered($query))
            ->limit($selection['limit'])->get()->values();
    }

    public static function learnSoon(Builder $query, Carbon $now): void
    {
        $query->where(function (Builder $query) use ($now): void {
            $query->where('cards.learn_soon', true)
                ->orWhere(fn ($query) => $query->where('cards.selection_policy', CardSelectionPolicy::ReviewSoon->value)
                    ->where('cards.priority_until', '>', $now));
        });
    }
}
