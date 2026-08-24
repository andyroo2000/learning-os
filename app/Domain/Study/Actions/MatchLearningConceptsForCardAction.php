<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Japanese\Contracts\JapaneseTokenizer;
use App\Domain\Study\Enums\LearningConceptMatchMethod;
use App\Domain\Study\Enums\LearningConceptMatchSource;
use App\Domain\Study\Results\LearningConceptMatchResult;
use App\Domain\Study\Support\LearningConceptText;
use Illuminate\Support\Facades\DB;

final class MatchLearningConceptsForCardAction
{
    public const CLASSIFIER_VERSION = 'n5-rules-v3';

    private const FURIGANA_FIELD_PATTERN = '/^([^\s\[\]]+)\[([^\[\]]+)]$/u';

    public function __construct(private readonly JapaneseTokenizer $tokenizer) {}

    public function handle(Card $card, LearningConceptMatchSource $source, bool $persist = true): LearningConceptMatchResult
    {
        $candidates = $this->candidates($card);
        $matches = [];
        $tokenCandidates = $this->tokenCandidates($candidates);
        $normalizedCandidates = array_values(array_unique([
            ...array_column($candidates, 'normalized'),
            ...array_keys($tokenCandidates),
        ]));

        if ($normalizedCandidates !== []) {
            $vocabularyAliases = DB::table('learning_concept_aliases as aliases')
                ->join('learning_concepts as concepts', 'concepts.id', '=', 'aliases.concept_id')
                ->where('concepts.language', 'ja')
                ->where('concepts.jlpt_level', 5)
                ->where('concepts.kind', 'vocabulary')
                ->whereIn('aliases.alias_kind', ['expression', 'reading'])
                ->whereIn('aliases.normalized_key', $normalizedCandidates)
                ->get(['aliases.concept_id', 'aliases.alias_kind', 'aliases.normalized_key']);

            // Exact spelling and reading keys can still be homographs/homophones. A key
            // such as あつい must not credit every unrelated word that shares its reading.
            $unambiguousVocabularyAliases = $vocabularyAliases
                ->groupBy('normalized_key')
                ->filter(fn ($aliases): bool => $aliases->unique('concept_id')->count() === 1)
                ->values();

            foreach ($unambiguousVocabularyAliases as $aliases) {
                $alias = $aliases->first();
                $candidate = $this->candidate($candidates, $alias->normalized_key);

                if ($candidate !== null) {
                    $matches[$alias->concept_id] = [
                        'method' => LearningConceptMatchMethod::Exact,
                        'confidence' => 1.0,
                        'evidence' => ['field' => $candidate['field'], 'matchedText' => $candidate['raw'], 'aliasKind' => $alias->alias_kind],
                    ];

                    continue;
                }

                $expressionAlias = $aliases->firstWhere('alias_kind', 'expression');
                $tokenCandidate = $tokenCandidates[$alias->normalized_key] ?? null;

                if ($expressionAlias !== null && $tokenCandidate !== null) {
                    $matches[$expressionAlias->concept_id] = [
                        'method' => LearningConceptMatchMethod::Token,
                        'confidence' => 0.9,
                        'evidence' => [
                            'field' => $tokenCandidate['field'],
                            'matchedText' => $tokenCandidate['raw'],
                            'token' => $tokenCandidate['token'],
                            'tokenForm' => $tokenCandidate['form'],
                        ],
                    ];
                }
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

            if (preg_match(self::FURIGANA_FIELD_PATTERN, trim($raw), $parts) === 1) {
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
     * @return array{field: string, raw: string, normalized: string}|null
     */
    private function candidate(array $candidates, string $normalized): ?array
    {
        foreach ($candidates as $candidate) {
            if ($candidate['normalized'] === $normalized) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<array{field: string, raw: string, normalized: string}>  $candidates
     * @return array<string, array{field: string, raw: string, token: string, form: string}>
     */
    private function tokenCandidates(array $candidates): array
    {
        $tokenizable = [];

        foreach ($candidates as $candidate) {
            if ($this->isReadingField($candidate['field'])
                || preg_match(self::FURIGANA_FIELD_PATTERN, trim($candidate['raw'])) === 1
            ) {
                continue;
            }

            $candidate['tokenizationText'] = preg_replace('/\[[^\]]+]/u', '', $candidate['raw']) ?? $candidate['raw'];
            $tokenizable[] = $candidate;
        }

        $tokenGroups = $this->tokenizer->tokenize(array_column($tokenizable, 'tokenizationText'));
        $result = [];

        foreach ($tokenizable as $index => $candidate) {
            foreach ($tokenGroups[$index] ?? [] as $token) {
                foreach (['surface', 'base'] as $form) {
                    $normalized = LearningConceptText::normalize($token[$form]);

                    if ($normalized === '') {
                        continue;
                    }

                    $result[$normalized] ??= [
                        'field' => $candidate['field'],
                        'raw' => $candidate['raw'],
                        'token' => $token[$form],
                        'form' => $form,
                    ];
                }
            }
        }

        return $result;
    }

    private function isReadingField(string $field): bool
    {
        $field = strtolower($field);

        return str_contains($field, 'reading') || str_contains($field, 'kana');
    }
}
