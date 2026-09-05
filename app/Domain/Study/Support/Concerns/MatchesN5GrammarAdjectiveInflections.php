<?php

namespace App\Domain\Study\Support\Concerns;

trait MatchesN5GrammarAdjectiveInflections
{
    /** @param list<array<string, string>> $tokens */
    private function hasIAdjectiveInflection(array $tokens, array $suffixes): bool
    {
        foreach ($tokens as $index => $token) {
            if (! $this->isIAdjective($token)) {
                continue;
            }

            if ($this->adjectiveMatchesAnySuffix($tokens, $index, $token, $suffixes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, string>>  $tokens
     * @param  array<string, string>  $token
     * @param  list<string>  $suffixes
     */
    private function adjectiveMatchesAnySuffix(array $tokens, int $index, array $token, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if ($this->adjectiveTailMatches($tokens, $index, $token, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, string>>  $tokens
     * @param  array<string, string>  $token
     */
    private function adjectiveTailMatches(array $tokens, int $index, array $token, string $suffix): bool
    {
        $phrase = '';
        $maximumLength = mb_strlen($token['normalizedSurface'].$suffix, 'UTF-8');

        for ($tailIndex = $index; $tailIndex < count($tokens); $tailIndex++) {
            $phrase .= $tokens[$tailIndex]['normalizedSurface'];

            if (str_ends_with($phrase, $suffix)) {
                return true;
            }

            if (mb_strlen($phrase, 'UTF-8') > $maximumLength) {
                return false;
            }
        }

        return false;
    }
}
