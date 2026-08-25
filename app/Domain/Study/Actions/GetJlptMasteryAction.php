<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Enums\LearningConceptKind;
use App\Domain\Study\Enums\LearningConceptReviewStatus;
use App\Domain\Study\Enums\StudyMasteryLevel;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class GetJlptMasteryAction
{
    /**
     * @return array{N5: array{vocabulary: array{mastery_percent: int, known: int, known_from_cards: int, known_from_wanikani: int, known_from_both: int, matched: int, covered: int, total: int}, grammar: array{mastery_percent: int, known: int, known_from_cards: int, known_from_wanikani: int, known_from_both: int, matched: int, covered: int, total: int}}, N4: array{vocabulary: array{mastery_percent: int, known: int, known_from_cards: int, known_from_wanikani: int, known_from_both: int, matched: int, covered: int, total: int}, grammar: array{mastery_percent: int, known: int, known_from_cards: int, known_from_wanikani: int, known_from_both: int, matched: int, covered: int, total: int}}}
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
            ->where(function ($query): void {
                $query
                    ->whereNull('cards.variant_status')
                    ->orWhere('cards.variant_status', VocabVariantStatus::Available->value);
            })
            ->when($courseId !== null, fn ($query) => $query->where('decks.course_id', $courseId))
            ->when($deckId !== null, fn ($query) => $query->where('cards.deck_id', $deckId))
            ->groupBy('links.concept_id')
            ->select('links.concept_id')
            ->selectRaw(<<<SQL
                MAX(CASE
                    WHEN cards.study_status = ? AND {$stability} >= ? THEN 1
                    ELSE 0
                END) AS known_weight
                SQL, [CardStudyStatus::Review->value, StudyMasteryLevel::GURU_STABILITY_DAYS]);

        $includeWaniKani = $courseId === null && $deckId === null;
        $wanikaniKnown = DB::table('user_wanikani_assignments as assignments')
            ->join(
                'wanikani_subject_learning_concepts as links',
                'links.subject_id',
                '=',
                'assignments.subject_id',
            )
            ->where('assignments.user_id', $userId)
            ->whereNotNull('assignments.passed_at')
            ->distinct()
            ->select('links.concept_id');

        $wanikaniKnownPredicate = $includeWaniKani
            ? 'wanikani_known.concept_id IS NOT NULL'
            : '0 = 1';
        $rows = DB::table('learning_concepts as concepts')
            ->leftJoinSub($bestCard, 'best_card', 'best_card.concept_id', '=', 'concepts.id')
            // Scoped calls describe only the selected card set, so they skip the
            // user-wide WaniKani join instead of executing a neutralized subquery.
            ->when(
                $includeWaniKani,
                fn ($query) => $query->leftJoinSub(
                    $wanikaniKnown,
                    'wanikani_known',
                    'wanikani_known.concept_id',
                    '=',
                    'concepts.id',
                ),
            )
            ->where('concepts.language', 'ja')
            ->whereIn('concepts.jlpt_level', [4, 5])
            ->whereIn('concepts.review_status', [
                LearningConceptReviewStatus::Seed->value,
                LearningConceptReviewStatus::Draft->value,
            ])
            ->groupBy('concepts.jlpt_level', 'concepts.kind')
            ->select('concepts.jlpt_level', 'concepts.kind')
            ->selectRaw('COUNT(concepts.id) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN best_card.concept_id IS NULL THEN 0 ELSE 1 END), 0) AS covered')
            ->selectRaw("COALESCE(SUM(CASE WHEN best_card.known_weight = 1 OR {$wanikaniKnownPredicate} THEN 1 ELSE 0 END), 0) AS known_count")
            ->selectRaw('COALESCE(SUM(CASE WHEN best_card.known_weight = 1 THEN 1 ELSE 0 END), 0) AS known_from_cards')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$wanikaniKnownPredicate} THEN 1 ELSE 0 END), 0) AS known_from_wanikani")
            ->selectRaw("COALESCE(SUM(CASE WHEN best_card.known_weight = 1 AND {$wanikaniKnownPredicate} THEN 1 ELSE 0 END), 0) AS known_from_both")
            ->get()
            ->keyBy(fn (object $row): string => (string) $row->jlpt_level.'-'.$row->kind);

        return [
            'N5' => [
                'vocabulary' => $this->metric($rows->get('5-'.LearningConceptKind::Vocabulary->value)),
                'grammar' => $this->metric($rows->get('5-'.LearningConceptKind::Grammar->value)),
            ],
            'N4' => [
                'vocabulary' => $this->metric($rows->get('4-'.LearningConceptKind::Vocabulary->value)),
                'grammar' => $this->metric($rows->get('4-'.LearningConceptKind::Grammar->value)),
            ],
        ];
    }

    /**
     * @return array{mastery_percent: int, known: int, known_from_cards: int, known_from_wanikani: int, known_from_both: int, matched: int, covered: int, total: int}
     */
    private function metric(?object $row): array
    {
        $total = (int) ($row?->total ?? 0);
        $known = (int) ($row?->known_count ?? 0);
        $matched = (int) ($row?->covered ?? 0);

        return [
            'mastery_percent' => $total === 0 ? 0 : (int) round(($known / $total) * 100),
            'known' => $known,
            'known_from_cards' => (int) ($row?->known_from_cards ?? 0),
            'known_from_wanikani' => (int) ($row?->known_from_wanikani ?? 0),
            'known_from_both' => (int) ($row?->known_from_both ?? 0),
            'matched' => $matched,
            // Retain the original key for older clients while they migrate to matched.
            'covered' => $matched,
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
