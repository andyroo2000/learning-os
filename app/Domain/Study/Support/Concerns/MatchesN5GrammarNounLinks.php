<?php

namespace App\Domain\Study\Support\Concerns;

trait MatchesN5GrammarNounLinks
{
    /** @param list<array<string, string>> $tokens */
    private function hasNounParticleNoun(array $tokens, string $surface): bool
    {
        for ($index = 1; $index < count($tokens) - 1; $index++) {
            if (($tokens[$index]['normalizedSurface'] ?? '') !== $surface) {
                continue;
            }

            if (! $this->isParticle($tokens[$index])) {
                continue;
            }

            if (! $this->isNoun($tokens[$index - 1])) {
                continue;
            }

            if ($this->isNoun($tokens[$index + 1])) {
                return true;
            }
        }

        return false;
    }
}
