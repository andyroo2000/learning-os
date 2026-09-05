<?php

namespace App\Domain\Study\Support\Concerns;

trait MatchesN5GrammarParticles
{
    /** @param list<array<string, string>> $tokens */
    private function hasParticle(array $tokens, string $surface): bool
    {
        foreach ($tokens as $token) {
            if (($token['normalizedSurface'] ?? '') === $surface && $this->isParticle($token)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function hasCaseParticle(array $tokens, string $surface): bool
    {
        foreach ($tokens as $index => $token) {
            if (($token['normalizedSurface'] ?? '') !== $surface || ! $this->isParticle($token)) {
                continue;
            }

            // UniDic classifies the copular で in ではありません as a case
            // particle. Do not turn a negative copula into a means/location hit.
            if ($surface === 'で' && $this->isNegativeCopulaDe($tokens, $index)) {
                continue;
            }

            if ($this->isCaseParticleToken($token)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function isNegativeCopulaDe(array $tokens, int $index): bool
    {
        $previous = $tokens[$index - 1] ?? [];

        if (! $this->isNoun($previous) && ! $this->isNaAdjective($previous)) {
            return false;
        }

        return $this->tokensMatchAt($tokens, $index, 'ではありません');
    }

    /** @param array<string, string> $token */
    private function isCaseParticleToken(array $token): bool
    {
        $partOfSpeech = $token['partOfSpeech'] ?? '';
        $partOfSpeechSubtype = $token['partOfSpeechSubtype'] ?? '';

        if (str_contains($partOfSpeech.' '.$partOfSpeechSubtype, '格助詞')) {
            return true;
        }

        if ($partOfSpeech !== '助詞') {
            return false;
        }

        return in_array($partOfSpeechSubtype, ['', '*'], true);
    }
}
