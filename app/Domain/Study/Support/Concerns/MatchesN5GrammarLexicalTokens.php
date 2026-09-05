<?php

namespace App\Domain\Study\Support\Concerns;

use App\Domain\Study\Support\LearningConceptText;

trait MatchesN5GrammarLexicalTokens
{
    /**
     * @param  list<array<string, string>>  $tokens
     * @return list<list<array<string, string>>>
     */
    private function significantTokenSegments(array $tokens): array
    {
        $segments = [];
        $segment = [];

        foreach ($tokens as $token) {
            $surface = LearningConceptText::normalize($token['surface'] ?? '');
            $partOfSpeech = $token['partOfSpeech'] ?? '';

            if (! $this->isSignificantToken($surface, $partOfSpeech)) {
                if ($segment !== []) {
                    $segments[] = $segment;
                    $segment = [];
                }

                continue;
            }

            $token['normalizedSurface'] = $surface;
            $segment[] = $token;
        }

        if ($segment !== []) {
            $segments[] = $segment;
        }

        return $segments;
    }

    private function isSignificantToken(string $surface, string $partOfSpeech): bool
    {
        if ($surface === '') {
            return false;
        }

        if (str_starts_with($partOfSpeech, '記号')) {
            return false;
        }

        return ! str_starts_with($partOfSpeech, '補助記号');
    }

    /** @param list<array<string, string>> $tokens */
    private function hasSuffixAfter(array $tokens, string $suffix, callable $category): bool
    {
        for ($index = 0; $index < count($tokens); $index++) {
            if ($category($tokens[$index]) && $this->tokensMatchAt($tokens, $index + 1, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function particleCount(array $tokens, string $surface): int
    {
        return count(array_filter(
            $tokens,
            fn (array $token): bool => ($token['normalizedSurface'] ?? '') === $surface && $this->isParticle($token),
        ));
    }

    private function isNoun(array $token): bool
    {
        $partOfSpeech = $token['partOfSpeech'] ?? '';
        $subtype = $token['partOfSpeechSubtype'] ?? '';

        return (str_starts_with($partOfSpeech, '名詞') || str_starts_with($partOfSpeech, '代名詞'))
            && ! str_contains($partOfSpeech.' '.$subtype, '形状詞')
            && ! str_contains($partOfSpeech.' '.$subtype, '形容動詞');
    }

    private function isVerb(array $token): bool
    {
        return str_starts_with($token['partOfSpeech'] ?? '', '動詞');
    }

    private function isIAdjective(array $token): bool
    {
        return str_starts_with($token['partOfSpeech'] ?? '', '形容詞');
    }

    private function isNaAdjective(array $token): bool
    {
        $features = ($token['partOfSpeech'] ?? '').' '.($token['partOfSpeechSubtype'] ?? '');

        return str_contains($features, '形状詞') || str_contains($features, '形容動詞');
    }

    private function isParticle(array $token): bool
    {
        return str_starts_with($token['partOfSpeech'] ?? '', '助詞');
    }

    private function isAuxiliary(array $token): bool
    {
        return str_starts_with($token['partOfSpeech'] ?? '', '助動詞');
    }
}
