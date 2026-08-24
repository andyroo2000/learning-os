<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Enums\LearningConceptKind;
use App\Domain\Study\Enums\LearningConceptReviewStatus;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class GetJlptMasteryAction
{
    /**
     * @return array{N5: array{vocabulary: array{mastery_percent: int, covered: int, total: int}, grammar: array{mastery_percent: int, covered: int, total: int}}}
     */
    public function handle(int $userId, ?string $courseId = null, ?string $deckId = null): array
    {
        $stability = $this->schedulerStabilityExpression();
        $bestCard = DB::table('card_learning_concepts as links')
            ->join('cards', 'cards.id', '=', 'links.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('cards.deleted_at')
            ->whereNull('decks.deleted_at')
            ->when($courseId !== null, fn ($query) => $query->where('decks.course_id', $courseId))
            ->when($deckId !== null, fn ($query) => $query->where('cards.deck_id', $deckId))
            ->groupBy('links.concept_id')
            ->select('links.concept_id')
            ->selectRaw(<<<SQL
                MAX(CASE
                    WHEN cards.study_status = ? AND {$stability} >= 365 THEN 1.0
                    WHEN cards.study_status = ? AND {$stability} >= 90 THEN 0.75
                    WHEN cards.study_status = ? AND {$stability} >= 30 THEN 0.5
                    WHEN cards.study_status = ? AND {$stability} >= 7 THEN 0.25
                    ELSE 0.0
                END) AS mastery_weight
                SQL, array_fill(0, 4, CardStudyStatus::Review->value));

        $rows = DB::table('learning_concepts as concepts')
            ->leftJoinSub($bestCard, 'best_card', 'best_card.concept_id', '=', 'concepts.id')
            ->where('concepts.language', 'ja')
            ->where('concepts.jlpt_level', 5)
            ->whereIn('concepts.review_status', [
                LearningConceptReviewStatus::Seed->value,
                LearningConceptReviewStatus::Draft->value,
            ])
            ->groupBy('concepts.kind')
            ->select('concepts.kind')
            ->selectRaw('COUNT(concepts.id) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN best_card.concept_id IS NULL THEN 0 ELSE 1 END), 0) AS covered')
            ->selectRaw('COALESCE(SUM(best_card.mastery_weight), 0) AS mastery_points')
            ->get()
            ->keyBy('kind');

        return [
            'N5' => [
                'vocabulary' => $this->metric($rows->get(LearningConceptKind::Vocabulary->value)),
                'grammar' => $this->metric($rows->get(LearningConceptKind::Grammar->value)),
            ],
        ];
    }

    /** @return array{mastery_percent: int, covered: int, total: int} */
    private function metric(?object $row): array
    {
        $total = (int) ($row?->total ?? 0);

        return [
            'mastery_percent' => $total === 0 ? 0 : (int) round(((float) $row->mastery_points / $total) * 100),
            'covered' => (int) ($row?->covered ?? 0),
            'total' => $total,
        ];
    }

    private function schedulerStabilityExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(json_extract(cards.scheduler_state, '$.stability') AS REAL)",
            'pgsql' => "CAST(cards.scheduler_state->>'stability' AS DOUBLE PRECISION)",
            'mysql' => "CAST(JSON_UNQUOTE(JSON_EXTRACT(cards.scheduler_state, '$.stability')) AS DECIMAL(20, 6))",
            default => throw new UnexpectedValueException('Unsupported database driver for JLPT mastery aggregation.'),
        };
    }
}
