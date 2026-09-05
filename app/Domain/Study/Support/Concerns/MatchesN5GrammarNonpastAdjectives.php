<?php

namespace App\Domain\Study\Support\Concerns;

use App\Domain\Study\Support\LearningConceptText;

trait MatchesN5GrammarNonpastAdjectives
{
    /** @param list<array<string, string>> $tokens */
    private function hasNonpastIAdjective(array $tokens): bool
    {
        foreach ($tokens as $index => $token) {
            if (! $this->isIAdjective($token)) {
                continue;
            }

            $surface = LearningConceptText::normalize($token['surface'] ?? '');
            $base = LearningConceptText::normalize($token['base'] ?? '');

            if ($this->isExcludedNonpastAdjective($surface, $base)) {
                continue;
            }

            $form = $token['conjugationForm'] ?? '';
            $next = $tokens[$index + 1]['normalizedSurface'] ?? '';

            if ($this->isNonpastAdjectiveForm($form, $next)) {
                return true;
            }
        }

        return false;
    }

    private function isExcludedNonpastAdjective(string $surface, string $base): bool
    {
        if (in_array($surface, ['ない', '無い', 'たい'], true)) {
            return true;
        }

        return in_array($base, ['ない', '無い', 'たい'], true);
    }

    private function isNonpastAdjectiveForm(string $form, string $next): bool
    {
        if ($form === '') {
            return true;
        }

        if (str_contains($form, '終止')) {
            return true;
        }

        if (str_contains($form, '基本')) {
            return true;
        }

        return $next === 'です';
    }
}
