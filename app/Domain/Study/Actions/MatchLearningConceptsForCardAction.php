<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Japanese\Contracts\JapaneseTokenizer;
use App\Domain\Japanese\Exceptions\JapaneseTokenizationException;
use App\Domain\Study\Enums\LearningConceptMatchMethod;
use App\Domain\Study\Enums\LearningConceptMatchSource;
use App\Domain\Study\Results\LearningConceptMatchResult;
use App\Domain\Study\Support\LearningConceptText;
use App\Domain\Study\Support\N4GrammarRuleMatcher;
use App\Domain\Study\Support\N5GrammarRuleMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MatchLearningConceptsForCardAction
{
    public const CLASSIFIER_VERSION = 'jlpt-n5-n4-rules-v5';

    private const FURIGANA_FIELD_PATTERN = '/^([^\s\[\]]+)\[([^\[\]]+)]$/u';

    public function __construct(
        private readonly JapaneseTokenizer $tokenizer,
        private readonly N5GrammarRuleMatcher $grammarMatcher,
        private readonly N4GrammarRuleMatcher $n4GrammarMatcher,
    ) {}

    public function handle(Card $card, LearningConceptMatchSource $source, bool $persist = true): LearningConceptMatchResult
    {
        $matches = $this->matches($card, $source);

        if (! $persist) {
            return new LearningConceptMatchResult(count($matches), 0);
        }

        $persistedCount = DB::transaction(
            fn (): int => $this->persistMatches($card, $matches, $source),
        );

        return new LearningConceptMatchResult(count($matches), $persistedCount);
    }

    /** @return array<string, array{method: LearningConceptMatchMethod, confidence: float, evidence: array<string, mixed>}> */
    private function matches(Card $card, LearningConceptMatchSource $source): array
    {
        $candidates = $this->candidates($card);
        $tokenizedCandidates = $this->tokenizedCandidates($candidates);
        $tokenCandidates = $this->tokenCandidates($tokenizedCandidates);
        $this->assertTokenizationSucceeded($source);
        $normalizedCandidates = array_values(array_unique([
            ...array_column($candidates, 'normalized'),
            ...array_keys($tokenCandidates),
        ]));

        if ($normalizedCandidates === []) {
            return [];
        }

        $matches = $this->vocabularyMatches($candidates, $tokenCandidates, $normalizedCandidates);
        $this->appendClassifierMatches($matches, $this->grammarMatcher->match($tokenizedCandidates));
        $this->appendClassifierMatches($matches, $this->n4GrammarMatcher->match($tokenizedCandidates));

        return $matches;
    }

    private function assertTokenizationSucceeded(LearningConceptMatchSource $source): void
    {
        if ($source === LearningConceptMatchSource::Backfill && $this->tokenizer->hadFailure()) {
            throw new JapaneseTokenizationException('Japanese tokenization failed during concept backfill.');
        }
    }

    /**
     * @param  list<array{field: string, raw: string, normalized: string}>  $candidates
     * @param  array<string, array{field: string, raw: string, token: string, form: string}>  $tokenCandidates
     * @param  list<string>  $normalizedCandidates
     * @return array<string, array{method: LearningConceptMatchMethod, confidence: float, evidence: array<string, mixed>}>
     */
    private function vocabularyMatches(
        array $candidates,
        array $tokenCandidates,
        array $normalizedCandidates,
    ): array {
        $aliasesByKey = DB::table('learning_concept_aliases as aliases')
            ->join('learning_concepts as concepts', 'concepts.id', '=', 'aliases.concept_id')
            ->where('concepts.language', 'ja')
            ->whereIn('concepts.jlpt_level', [4, 5])
            ->where('concepts.kind', 'vocabulary')
            ->whereIn('aliases.alias_kind', ['expression', 'reading'])
            ->whereIn('aliases.normalized_key', $normalizedCandidates)
            ->get(['aliases.concept_id', 'aliases.alias_kind', 'aliases.normalized_key'])
            ->groupBy('normalized_key')
            ->filter(fn ($aliases): bool => $aliases->unique('concept_id')->count() === 1)
            ->values();
        $matches = [];

        // Exact spelling and reading keys can still be homographs/homophones. A key
        // such as あつい must not credit every unrelated word that shares its reading.
        foreach ($aliasesByKey as $aliases) {
            $this->appendVocabularyMatch($matches, $aliases, $candidates, $tokenCandidates);
        }

        return $matches;
    }

    /**
     * @param  array<string, array{method: LearningConceptMatchMethod, confidence: float, evidence: array<string, mixed>}>  $matches
     * @param  Collection<int, object{concept_id: string, alias_kind: string, normalized_key: string}>  $aliases
     * @param  list<array{field: string, raw: string, normalized: string}>  $candidates
     * @param  array<string, array{field: string, raw: string, token: string, form: string}>  $tokenCandidates
     */
    private function appendVocabularyMatch(array &$matches, Collection $aliases, array $candidates, array $tokenCandidates): void
    {
        $alias = $aliases->first();
        $candidate = $this->candidate($candidates, $alias->normalized_key);

        if ($candidate !== null) {
            $matches[$alias->concept_id] = [
                'method' => LearningConceptMatchMethod::Exact,
                'confidence' => 1.0,
                'evidence' => ['field' => $candidate['field'], 'matchedText' => $candidate['raw'], 'aliasKind' => $alias->alias_kind],
            ];

            return;
        }

        $expressionAlias = $aliases->firstWhere('alias_kind', 'expression');
        $tokenCandidate = $tokenCandidates[$alias->normalized_key] ?? null;

        if ($expressionAlias === null || $tokenCandidate === null) {
            return;
        }

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

    /**
     * @param  array<string, array{method: LearningConceptMatchMethod, confidence: float, evidence: array<string, mixed>}>  $matches
     * @param  array<string, array<string, mixed>>  $classifierMatches
     */
    private function appendClassifierMatches(array &$matches, array $classifierMatches): void
    {
        foreach ($classifierMatches as $conceptId => $evidence) {
            $matches[$conceptId] = [
                'method' => LearningConceptMatchMethod::Classifier,
                'confidence' => 0.85,
                'evidence' => $evidence,
            ];
        }
    }

    /**
     * @param  array<string, array{method: LearningConceptMatchMethod, confidence: float, evidence: array<string, mixed>}>  $matches
     */
    private function persistMatches(
        Card $card,
        array $matches,
        LearningConceptMatchSource $source,
    ): int {
        $manualConceptIds = DB::table('card_learning_concepts')
            ->where('card_id', $card->getKey())
            ->where('match_source', LearningConceptMatchSource::Manual->value)
            ->pluck('concept_id')
            ->all();

        DB::table('card_learning_concepts')
            ->where('card_id', $card->getKey())
            ->where('match_source', '!=', LearningConceptMatchSource::Manual->value)
            ->delete();

        $rows = $this->matchRows($card, $matches, $manualConceptIds, $source);

        if ($rows !== []) {
            DB::table('card_learning_concepts')->insert($rows);
        }

        return count($rows);
    }

    /**
     * @param  array<string, array{method: LearningConceptMatchMethod, confidence: float, evidence: array<string, mixed>}>  $matches
     * @param  list<string>  $manualConceptIds
     * @return list<array<string, mixed>>
     */
    private function matchRows(
        Card $card,
        array $matches,
        array $manualConceptIds,
        LearningConceptMatchSource $source,
    ): array {
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

        return $rows;
    }

    /** @return list<array{field: string, raw: string, normalized: string}> */
    private function candidates(Card $card): array
    {
        $values = $this->candidateValues($card);
        $candidates = [];

        foreach ($values as $field => $raw) {
            $this->appendValueCandidates($candidates, $field, $raw);
        }

        return array_values(array_unique($candidates, SORT_REGULAR));
    }

    /** @return array<string, mixed> */
    private function candidateValues(Card $card): array
    {
        return [
            'frontText' => $card->front_text,
            'backText' => $card->back_text,
            ...$this->stringValues('prompt.', $card->prompt_json ?? []),
            ...$this->stringValues('answer.', $card->answer_json ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function stringValues(string $prefix, array $values): array
    {
        $strings = [];

        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $strings[$prefix.$key] = $value;
            }
        }

        return $strings;
    }

    /** @param list<array{field: string, raw: string, normalized: string}> $candidates */
    private function appendValueCandidates(array &$candidates, string $field, mixed $raw): void
    {
        if (! is_string($raw) || ! LearningConceptText::containsJapanese($raw)) {
            return;
        }

        $this->appendCandidate($candidates, $field, $raw);

        if (preg_match(self::FURIGANA_FIELD_PATTERN, trim($raw), $parts) !== 1) {
            return;
        }

        $this->appendCandidate($candidates, $field.'.expression', $parts[1]);
        $this->appendCandidate($candidates, $field.'.reading', $parts[2]);
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
     * @return list<array{field: string, raw: string, normalized: string, tokens: list<array<string, string>>}>
     */
    private function tokenizedCandidates(array $candidates): array
    {
        $tokenizable = [];

        foreach ($candidates as $candidate) {
            if ($this->isReadingField($candidate['field'])
                || preg_match(self::FURIGANA_FIELD_PATTERN, trim($candidate['raw'])) === 1
            ) {
                continue;
            }

            $candidate['tokenizationText'] = preg_replace('/\[[^\]]+]/u', '', $candidate['raw']) ?? $candidate['raw'];
            $candidate['normalized'] = LearningConceptText::normalize($candidate['tokenizationText']);
            $tokenizable[] = $candidate;
        }

        $tokenGroups = $this->tokenizer->tokenize(array_column($tokenizable, 'tokenizationText'));

        foreach ($tokenizable as $index => $candidate) {
            $tokenizable[$index]['tokens'] = $tokenGroups[$index] ?? [];
            unset($tokenizable[$index]['tokenizationText']);
        }

        return $tokenizable;
    }

    /**
     * @param  list<array{field: string, raw: string, normalized: string, tokens: list<array<string, string>>}>  $candidates
     * @return array<string, array{field: string, raw: string, token: string, form: string}>
     */
    private function tokenCandidates(array $candidates): array
    {
        $result = [];

        foreach ($candidates as $candidate) {
            foreach ($candidate['tokens'] as $token) {
                $this->appendTokenForms($result, $candidate, $token);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array{field: string, raw: string, token: string, form: string}>  $result
     * @param  array{field: string, raw: string, normalized: string, tokens: list<array<string, string>>}  $candidate
     * @param  array<string, string>  $token
     */
    private function appendTokenForms(array &$result, array $candidate, array $token): void
    {
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

    private function isReadingField(string $field): bool
    {
        $field = strtolower($field);

        return str_contains($field, 'reading') || str_contains($field, 'kana');
    }
}
