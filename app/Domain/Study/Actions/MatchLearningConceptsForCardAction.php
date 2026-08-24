<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Enums\LearningConceptMatchMethod;
use App\Domain\Study\Enums\LearningConceptMatchSource;
use App\Domain\Study\Results\LearningConceptMatchResult;
use App\Domain\Study\Support\LearningConceptText;
use Illuminate\Support\Facades\DB;

final class MatchLearningConceptsForCardAction
{
    public const CLASSIFIER_VERSION = 'n5-rules-v2';

    public function handle(Card $card, LearningConceptMatchSource $source, bool $persist = true): LearningConceptMatchResult
    {
        $candidates = $this->candidates($card);
        $matches = [];
        $normalizedCandidates = array_values(array_unique(array_column($candidates, 'normalized')));

        if ($normalizedCandidates !== []) {
            $vocabularyAliases = DB::table('learning_concept_aliases as aliases')
                ->join('learning_concepts as concepts', 'concepts.id', '=', 'aliases.concept_id')
                ->where('concepts.language', 'ja')
                ->where('concepts.jlpt_level', 5)
                ->where('concepts.kind', 'vocabulary')
                ->whereIn('aliases.alias_kind', ['expression', 'reading'])
                ->whereIn('aliases.normalized_key', $normalizedCandidates)
                ->get(['aliases.concept_id', 'aliases.alias_kind', 'aliases.normalized_key']);

            foreach ($vocabularyAliases as $alias) {
                $candidate = $this->firstCandidate($candidates, $alias->normalized_key);
                $matches[$alias->concept_id] = [
                    'method' => LearningConceptMatchMethod::Exact,
                    'confidence' => 1.0,
                    'evidence' => ['field' => $candidate['field'], 'matchedText' => $candidate['raw'], 'aliasKind' => $alias->alias_kind],
                ];
            }

            $grammarAliases = DB::table('learning_concept_aliases as aliases')
                ->join('learning_concepts as concepts', 'concepts.id', '=', 'aliases.concept_id')
                ->where('concepts.language', 'ja')
                ->where('concepts.jlpt_level', 5)
                ->where('concepts.kind', 'grammar')
                ->where('aliases.alias_kind', 'surface')
                ->get(['aliases.concept_id', 'aliases.normalized_key']);

            // A short surface such as です can appear in several catalog patterns. Treating
            // every concept that shares it as demonstrated would fan one card out into a
            // collection of unrelated grammar points, so only use catalog-unique surfaces.
            $unambiguousGrammarAliases = $grammarAliases
                ->groupBy('normalized_key')
                ->filter(fn ($aliases): bool => $aliases->unique('concept_id')->count() === 1)
                ->map->first();

            foreach ($unambiguousGrammarAliases as $alias) {
                foreach ($candidates as $candidate) {
                    if (str_contains($candidate['normalized'], $alias->normalized_key)) {
                        $matches[$alias->concept_id] ??= [
                            'method' => LearningConceptMatchMethod::Surface,
                            'confidence' => 0.7,
                            'evidence' => ['field' => $candidate['field'], 'matchedText' => $candidate['raw'], 'surface' => $alias->normalized_key],
                        ];
                        break;
                    }
                }
            }
        }

        if (! $persist) {
            return new LearningConceptMatchResult(count($matches), 0);
        }

        $persistedCount = DB::transaction(function () use ($card, $matches, $source): int {
            $manualConceptIds = DB::table('card_learning_concepts')
                ->where('card_id', $card->getKey())
                ->where('match_source', LearningConceptMatchSource::Manual->value)
                ->pluck('concept_id')
                ->all();

            DB::table('card_learning_concepts')
                ->where('card_id', $card->getKey())
                ->where('match_source', '!=', LearningConceptMatchSource::Manual->value)
                ->delete();

            $now = now();
            $rows = [];

            foreach (array_diff_key($matches, array_flip($manualConceptIds)) as $conceptId => $match) {
                $rows[] = [
                    'card_id' => $card->getKey(),
                    'concept_id' => $conceptId,
                    'match_method' => $match['method']->value,
                    'match_source' => $source->value,
                    'confidence' => $match['confidence'],
                    'classifier_version' => self::CLASSIFIER_VERSION,
                    'evidence' => json_encode($match['evidence'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('card_learning_concepts')->insert($rows);
            }

            return count($rows);
        });

        return new LearningConceptMatchResult(count($matches), $persistedCount);
    }

    /** @return list<array{field: string, raw: string, normalized: string}> */
    private function candidates(Card $card): array
    {
        $values = ['frontText' => $card->front_text, 'backText' => $card->back_text];

        foreach (($card->prompt_json ?? []) as $key => $value) {
            if (is_string($value)) {
                $values['prompt.'.$key] = $value;
            }
        }

        foreach (($card->answer_json ?? []) as $key => $value) {
            if (is_string($value)) {
                $values['answer.'.$key] = $value;
            }
        }

        $candidates = [];

        foreach ($values as $field => $raw) {
            if (! is_string($raw) || ! LearningConceptText::containsJapanese($raw)) {
                continue;
            }

            $this->appendCandidate($candidates, $field, $raw);

            if (preg_match('/^(.+?)\[([^\]]+)]$/u', trim($raw), $parts) === 1) {
                $this->appendCandidate($candidates, $field.'.expression', $parts[1]);
                $this->appendCandidate($candidates, $field.'.reading', $parts[2]);
            }
        }

        return array_values(array_unique($candidates, SORT_REGULAR));
    }

    /** @param list<array{field: string, raw: string, normalized: string}> $candidates */
    private function appendCandidate(array &$candidates, string $field, string $raw): void
    {
        $normalized = LearningConceptText::normalize($raw);

        if ($normalized !== '') {
            $candidates[] = compact('field', 'raw', 'normalized');
        }
    }

    /**
     * @param  list<array{field: string, raw: string, normalized: string}>  $candidates
     * @return array{field: string, raw: string, normalized: string}
     */
    private function firstCandidate(array $candidates, string $normalized): array
    {
        foreach ($candidates as $candidate) {
            if ($candidate['normalized'] === $normalized) {
                return $candidate;
            }
        }

        return $candidates[0];
    }
}
