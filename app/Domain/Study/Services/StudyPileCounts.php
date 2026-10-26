<?php

namespace App\Domain\Study\Services;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Support\StudyPileQueue;
use Illuminate\Database\Eloquent\Builder;

final class StudyPileCounts
{
    private const EMPTY_COUNTS = ['new_count' => 0, 'ready_count' => 0, 'introduced_count' => 0];

    public function forUser(int $userId): array
    {
        $rows = $this->aggregate(Card::query()->ownedByActiveDeck($userId))
            ->addSelect('cards.book_pile_id', 'cards.book_chapter_id')
            ->groupBy('cards.book_pile_id', 'cards.book_chapter_id')->get();
        $result = ['piles' => [], 'chapters' => [], 'uncategorized' => self::EMPTY_COUNTS];
        foreach ($rows as $row) {
            $counts = $this->counts($row);
            if ($row->book_pile_id === null) {
                $result['uncategorized'] = $counts;

                continue;
            }
            $existing = $result['piles'][$row->book_pile_id] ?? self::EMPTY_COUNTS;
            $result['piles'][$row->book_pile_id] = array_map(fn ($a, $b) => $a + $b, $existing, $counts);
            $result['piles'][$row->book_pile_id] = array_combine(array_keys(self::EMPTY_COUNTS), $result['piles'][$row->book_pile_id]);
            if ($row->book_chapter_id !== null) {
                $result['chapters'][$row->book_chapter_id] = $counts;
            }
        }
        $soon = Card::query()->ownedByActiveDeck($userId);
        StudyPileQueue::learnSoon($soon, now());
        $result['learn_soon'] = $this->counts($this->aggregate($soon)->first());

        return $result;
    }

    public function decorate(array $pile, array $counts): array
    {
        $pile = [...$pile, ...($counts['piles'][$pile['id']] ?? self::EMPTY_COUNTS)];
        $pile['chapters'] = array_map(fn ($chapter) => [
            ...$chapter, ...($counts['chapters'][$chapter['id']] ?? self::EMPTY_COUNTS),
        ], $pile['chapters']);

        return $pile;
    }

    private function aggregate(Builder $query): Builder
    {
        $activeSource = StudyPileQueue::eligibilitySql();

        return $query->select([])->selectRaw(<<<SQL
            SUM(CASE WHEN cards.study_status = 'new' THEN 1 ELSE 0 END) AS new_count,
            SUM(CASE WHEN cards.study_status = 'new'
                AND (cards.variant_status IS NULL OR cards.variant_status = 'available')
                AND cards.new_queue_position IS NOT NULL
                AND (cards.introduction_available_at IS NULL OR cards.introduction_available_at <= ?)
                AND {$activeSource} THEN 1 ELSE 0 END) AS ready_count,
            SUM(CASE WHEN cards.introduced_at IS NOT NULL THEN 1 ELSE 0 END) AS introduced_count
            SQL, [now()]);
    }

    private function counts(?object $row): array
    {
        return [
            'new_count' => (int) ($row?->new_count ?? 0),
            'ready_count' => (int) ($row?->ready_count ?? 0),
            'introduced_count' => (int) ($row?->introduced_count ?? 0),
        ];
    }
}
