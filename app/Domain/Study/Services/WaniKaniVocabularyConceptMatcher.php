<?php

namespace App\Domain\Study\Services;

use App\Domain\Japanese\Data\WaniKaniVocabularyProgress;
use App\Domain\Study\Enums\LearningConceptKind;
use App\Domain\Study\Enums\LearningConceptReviewStatus;
use App\Domain\Study\Support\LearningConceptText;
use Illuminate\Support\Facades\DB;

final class WaniKaniVocabularyConceptMatcher
{
    public const VERSION = 'wanikani-exact-v1';

    /**
     * @param  list<WaniKaniVocabularyProgress>  $progress
     * @return list<array{subject_id: int, concept_id: string, match_method: string, confidence: float}>
     */
    public function match(array $progress): array
    {
        return $this->matchSubjects(array_map(
            static fn (WaniKaniVocabularyProgress $item): array => [
                'subject_id' => $item->subjectId,
                'characters' => $item->characters,
                'readings' => $item->readings,
            ],
            $progress,
        ));
    }

    /**
     * @param  list<array{subject_id: int, characters: string, readings: list<string>}>  $subjects
     * @return list<array{subject_id: int, concept_id: string, match_method: string, confidence: float}>
     */
    public function matchSubjects(array $subjects): array
    {
        if ($subjects === []) {
            return [];
        }

        $aliases = DB::table('learning_concept_aliases as aliases')
            ->join('learning_concepts as concepts', 'concepts.id', '=', 'aliases.concept_id')
            ->where('concepts.language', 'ja')
            ->where('concepts.kind', LearningConceptKind::Vocabulary->value)
            ->whereIn('concepts.review_status', [
                LearningConceptReviewStatus::Seed->value,
                LearningConceptReviewStatus::Draft->value,
            ])
            ->whereIn('aliases.alias_kind', ['expression', 'reading'])
            ->get(['aliases.concept_id', 'aliases.alias_kind', 'aliases.normalized_key']);

        $expressions = [];
        $readings = [];
        foreach ($aliases as $alias) {
            if ($alias->alias_kind === 'expression') {
                $expressions[$alias->normalized_key][$alias->concept_id] = true;
            } else {
                $readings[$alias->concept_id][$alias->normalized_key] = true;
            }
        }

        $matches = [];
        foreach ($subjects as $subject) {
            $expression = LearningConceptText::normalize($subject['characters']);
            $candidateIds = array_keys($expressions[$expression] ?? []);
            $method = 'expression';

            if (count($candidateIds) > 1) {
                $normalizedReadings = array_fill_keys(
                    array_map(LearningConceptText::normalize(...), $subject['readings']),
                    true,
                );
                $candidateIds = array_values(array_filter(
                    $candidateIds,
                    fn (string $conceptId): bool => array_intersect_key(
                        $readings[$conceptId] ?? [],
                        $normalizedReadings,
                    ) !== [],
                ));
                $method = 'expression_reading';
            }

            // Exact homographs that remain ambiguous after reading comparison do not
            // receive credit; a false positive is worse than a small coverage miss.
            if (count($candidateIds) !== 1) {
                continue;
            }

            $matches[] = [
                'subject_id' => $subject['subject_id'],
                'concept_id' => $candidateIds[0],
                'match_method' => $method,
                'confidence' => 1.0,
            ];
        }

        return $matches;
    }
}
