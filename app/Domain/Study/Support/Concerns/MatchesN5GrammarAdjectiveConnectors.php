<?php

namespace App\Domain\Study\Support\Concerns;

trait MatchesN5GrammarAdjectiveConnectors
{
    /** @param list<array<string, string>> $tokens */
    private function hasIAdjectiveBeforeVerb(array $tokens): bool
    {
        for ($index = 0; $index < count($tokens) - 1; $index++) {
            if (! $this->isIAdjective($tokens[$index])) {
                continue;
            }

            if (! str_ends_with($tokens[$index]['normalizedSurface'] ?? '', 'く')) {
                continue;
            }

            if (! $this->isVerb($tokens[$index + 1])) {
                continue;
            }

            if (! $this->tokensMatchAt($tokens, $index + 1, 'ありません')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function hasCopularConnector(array $tokens): bool
    {
        for ($index = 1; $index < count($tokens); $index++) {
            if (($tokens[$index]['normalizedSurface'] ?? '') !== 'で') {
                continue;
            }

            if (! $this->isAuxiliary($tokens[$index])) {
                continue;
            }

            if ($this->isNaAdjective($tokens[$index - 1])) {
                return true;
            }

            if ($this->isNoun($tokens[$index - 1])) {
                return true;
            }
        }

        return false;
    }
}
