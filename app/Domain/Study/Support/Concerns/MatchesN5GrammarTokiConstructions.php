<?php

namespace App\Domain\Study\Support\Concerns;

trait MatchesN5GrammarTokiConstructions
{
    /** @param list<array<string, string>> $tokens */
    private function hasTokiConstruction(array $tokens): bool
    {
        foreach ($tokens as $index => $token) {
            $surface = $token['normalizedSurface'] ?? '';

            if ($surface === 'とき') {
                return true;
            }

            if ($surface !== '時') {
                continue;
            }

            if ($index === 0) {
                continue;
            }

            $previous = $tokens[$index - 1];

            if ($this->isNumericTokiPredecessor($previous)) {
                continue;
            }

            if ($this->isTokiModifier($previous)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $token */
    private function isNumericTokiPredecessor(array $token): bool
    {
        $features = ($token['partOfSpeech'] ?? '').' '.($token['partOfSpeechSubtype'] ?? '');

        if (str_contains($features, '数詞')) {
            return true;
        }

        return preg_match('/^[0-9０-９一二三四五六七八九十百何]+$/u', $token['normalizedSurface'] ?? '') === 1;
    }

    /** @param array<string, string> $token */
    private function isTokiModifier(array $token): bool
    {
        if (in_array($token['normalizedSurface'] ?? '', ['の', 'な'], true)) {
            return true;
        }

        if ($this->isNoun($token)) {
            return true;
        }

        if ($this->isVerb($token)) {
            return true;
        }

        if ($this->isIAdjective($token)) {
            return true;
        }

        return $this->isNaAdjective($token);
    }
}
