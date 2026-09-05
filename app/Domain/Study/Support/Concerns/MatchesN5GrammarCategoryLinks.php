<?php

namespace App\Domain\Study\Support\Concerns;

trait MatchesN5GrammarCategoryLinks
{
    /** @param list<array<string, string>> $tokens */
    private function hasCategorySurfaceCategory(array $tokens, callable $before, string $surface, callable $after): bool
    {
        for ($index = 1; $index < count($tokens) - 1; $index++) {
            if (($tokens[$index]['normalizedSurface'] ?? '') !== $surface) {
                continue;
            }

            if (! $before($tokens[$index - 1])) {
                continue;
            }

            if ($after($tokens[$index + 1])) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function hasPastPredicate(array $tokens): bool
    {
        return $this->hasVerbAuxiliary($tokens, 'た', 'だ')
            || $this->hasSuffixAfter($tokens, 'ました', $this->isVerb(...));
    }
}
