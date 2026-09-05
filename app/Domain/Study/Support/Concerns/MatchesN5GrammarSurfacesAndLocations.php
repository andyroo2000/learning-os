<?php

namespace App\Domain\Study\Support\Concerns;

trait MatchesN5GrammarSurfacesAndLocations
{
    /**
     * @param  list<array<string, string>>  $tokens
     * @param  list<string>  $surfaces
     */
    private function hasSurfaceBeforePhrase(array $tokens, array $surfaces, string $phrase): bool
    {
        for ($index = 0; $index < count($tokens) - 1; $index++) {
            if (! in_array($tokens[$index]['normalizedSurface'] ?? '', $surfaces, true)) {
                continue;
            }

            if ($this->tokensMatchAt($tokens, $index + 1, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function hasTokenSurface(array $tokens, string $surface): bool
    {
        foreach ($tokens as $token) {
            if (($token['normalizedSurface'] ?? '') === $surface) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, string>>  $tokens
     * @param  list<string>  $surfaces
     */
    private function hasAnyTokenSurface(array $tokens, array $surfaces): bool
    {
        foreach ($surfaces as $surface) {
            if ($this->hasTokenSurface($tokens, $surface)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function hasRelativeLocation(array $tokens): bool
    {
        $locations = ['上', '下', '中', '前', '後ろ', '横', '隣', '間'];

        for ($index = 1; $index < count($tokens) - 2; $index++) {
            if (($tokens[$index]['normalizedSurface'] ?? '') !== 'の') {
                continue;
            }

            if (! $this->isNoun($tokens[$index - 1])) {
                continue;
            }

            if (! in_array($tokens[$index + 1]['normalizedSurface'] ?? '', $locations, true)) {
                continue;
            }

            if (in_array($tokens[$index + 2]['normalizedSurface'] ?? '', ['に', 'で', 'へ'], true)) {
                return true;
            }
        }

        return false;
    }
}
