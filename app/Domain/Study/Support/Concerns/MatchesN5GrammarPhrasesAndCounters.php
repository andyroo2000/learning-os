<?php

namespace App\Domain\Study\Support\Concerns;

trait MatchesN5GrammarPhrasesAndCounters
{
    /** @param list<array<string, string>> $tokens */
    private function hasTokenPhrase(array $tokens, string $phrase): bool
    {
        for ($index = 0; $index < count($tokens); $index++) {
            if ($this->tokensMatchAt($tokens, $index, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, string>>  $tokens
     * @param  list<string>  $phrases
     */
    private function hasAnyTokenPhrase(array $tokens, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if ($this->hasTokenPhrase($tokens, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function hasNumberCounter(
        array $tokens,
        string $counter,
        string $numberPattern = '[0-9０-９一二三四五六七八九十百千万]+',
    ): bool {
        $counterPattern = [
            'counter' => $counter,
            'number' => $numberPattern,
        ];

        foreach ($tokens as $index => $token) {
            if ($this->tokenMatchesNumberCounter($tokens, $index, $token, $counterPattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, string>>  $tokens
     * @param  array<string, string>  $token
     * @param  array{counter: string, number: string}  $pattern
     */
    private function tokenMatchesNumberCounter(array $tokens, int $index, array $token, array $pattern): bool
    {
        $surface = $token['normalizedSurface'] ?? '';
        $counter = $pattern['counter'];

        if (preg_match('/^'.$pattern['number'].preg_quote($counter, '/').'$/u', $surface) === 1) {
            if ($counter !== '時') {
                return true;
            }

            return ($tokens[$index + 1]['normalizedSurface'] ?? '') !== '間';
        }

        if (preg_match('/^'.$pattern['number'].'$/u', $surface) !== 1) {
            return false;
        }

        if (! $this->tokensMatchAt($tokens, $index + 1, $counter)) {
            return false;
        }

        if ($counter !== '時') {
            return true;
        }

        if (($tokens[$index + 1]['normalizedSurface'] ?? '') !== '時') {
            return true;
        }

        return ($tokens[$index + 2]['normalizedSurface'] ?? '') !== '間';
    }

    /**
     * @param  list<array<string, string>>  $tokens
     * @param  list<string>  $counters
     */
    private function hasAnyNumberCounter(array $tokens, array $counters): bool
    {
        foreach ($counters as $counter) {
            if ($this->hasNumberCounter($tokens, $counter)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function tokensMatchAt(array $tokens, int $start, string $phrase): bool
    {
        $candidate = '';
        $phraseLength = mb_strlen($phrase, 'UTF-8');

        for ($index = $start; $index < count($tokens); $index++) {
            $candidate .= $tokens[$index]['normalizedSurface'];
            $candidateLength = mb_strlen($candidate, 'UTF-8');

            if ($candidateLength >= $phraseLength) {
                return $candidate === $phrase;
            }
        }

        return false;
    }
}
