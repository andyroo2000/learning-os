<?php

namespace App\Domain\Study\Support\Concerns;

trait MatchesN5GrammarParticleKinds
{
    /** @param list<array<string, string>> $tokens */
    private function hasParticleSubtype(array $tokens, string $surface, string $subtype): bool
    {
        foreach ($tokens as $index => $token) {
            if (($token['normalizedSurface'] ?? '') !== $surface || ! $this->isParticle($token)) {
                continue;
            }

            $features = ($token['partOfSpeech'] ?? '').' '.($token['partOfSpeechSubtype'] ?? '');

            if (str_contains($features, $subtype)) {
                return true;
            }

            $partOfSpeechSubtype = $token['partOfSpeechSubtype'] ?? '';

            if (! in_array($partOfSpeechSubtype, ['', '*'], true)) {
                continue;
            }

            $previous = $tokens[$index - 1] ?? null;

            if ($previous !== null && ($subtype === '格助詞' ? $this->isNoun($previous) : ! $this->isNoun($previous))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function hasSentenceEndingParticle(array $tokens, string $surface): bool
    {
        foreach ($tokens as $index => $token) {
            if (($token['normalizedSurface'] ?? '') !== $surface || ! $this->isParticle($token)) {
                continue;
            }

            $features = ($token['partOfSpeech'] ?? '').' '.($token['partOfSpeechSubtype'] ?? '');

            if (str_contains($features, '終助詞') || $index === count($tokens) - 1) {
                return true;
            }
        }

        return false;
    }
}
