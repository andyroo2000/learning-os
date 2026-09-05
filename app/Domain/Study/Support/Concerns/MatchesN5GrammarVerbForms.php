<?php

namespace App\Domain\Study\Support\Concerns;

use App\Domain\Study\Support\LearningConceptText;

trait MatchesN5GrammarVerbForms
{
    /** @param list<array<string, string>> $tokens */
    private function hasVerbConnectingParticle(array $tokens): bool
    {
        for ($index = 1; $index < count($tokens); $index++) {
            if (! in_array($tokens[$index]['normalizedSurface'] ?? '', ['て', 'で'], true)) {
                continue;
            }

            if (! $this->isParticle($tokens[$index])) {
                continue;
            }

            if ($this->isVerb($tokens[$index - 1])) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function hasVerbAuxiliary(array $tokens, string ...$surfaces): bool
    {
        for ($index = 1; $index < count($tokens); $index++) {
            $surface = $tokens[$index]['normalizedSurface'] ?? '';
            $base = LearningConceptText::normalize($tokens[$index]['base'] ?? '');

            if (! in_array($surface, $surfaces, true) && ! in_array($base, $surfaces, true)) {
                continue;
            }

            if ($this->isVerb($tokens[$index - 1])) {
                return true;
            }
        }

        return false;
    }
}
