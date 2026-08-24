<?php

namespace App\Domain\Study\Support;

final class N5GrammarRuleMatcher
{
    /** @var list<string> */
    public const SUPPORTED_CONCEPT_IDS = [
        'n5-grammar-desu-polite-copula',
        'n5-grammar-da-plain-copula',
        'n5-grammar-deshita-past-polite-copula',
        'n5-grammar-dewa-arimasen-negative-polite-copula',
        'n5-grammar-masu-polite-verb',
        'n5-grammar-mashita-polite-past-verb',
        'n5-grammar-masen-polite-negative-verb',
        'n5-grammar-masendeshita-polite-past-negative-verb',
        'n5-grammar-particle-wa-topic',
        'n5-grammar-particle-ga-subject',
        'n5-grammar-particle-o-object',
        'n5-grammar-particle-ni-target',
        'n5-grammar-particle-de-means',
        'n5-grammar-particle-to-and-with',
        'n5-grammar-particle-no-possession',
        'n5-grammar-particle-e-direction',
        'n5-grammar-particle-kara-from',
        'n5-grammar-particle-made-until',
        'n5-grammar-particle-mo-also',
        'n5-grammar-particle-ka-question',
        'n5-grammar-particle-ne-seeking-agreement',
        'n5-grammar-particle-yo-emphasis',
        'n5-grammar-kore-sore-are-demonstratives',
        'n5-grammar-kono-sono-ano-dono-attributive',
        'n5-grammar-koko-soko-asoko-doko',
        'n5-grammar-arimasu-existence-inanimate',
        'n5-grammar-imasu-existence-animate',
        'n5-grammar-te-form-basic',
        'n5-grammar-te-kudasai-request',
        'n5-grammar-te-imasu-progressive',
        'n5-grammar-nai-form',
        'n5-grammar-ta-form',
        'n5-grammar-i-adjective-nonpast',
        'n5-grammar-i-adjective-negative',
        'n5-grammar-i-adjective-past',
        'n5-grammar-na-adjective-nonpast',
        'n5-grammar-na-adjective-attributive',
        'n5-grammar-na-adjective-negative',
        'n5-grammar-na-adjective-past',
        'n5-grammar-mashou-volitional',
        'n5-grammar-mashou-ka-invitation',
        'n5-grammar-tai-desire',
        'n5-grammar-ga-hoshii-wanting-thing',
        'n5-grammar-hou-ga-comparative',
        'n5-grammar-ichiban-superlative',
        'n5-grammar-counter-tsu',
        'n5-grammar-counter-people-nin',
        'n5-grammar-question-words-basic',
        'n5-grammar-nai-de-kudasai',
        'n5-grammar-particle-ya-non-exhaustive',
        'n5-grammar-masenka-invitation',
        'n5-grammar-mou-ta-already',
        'n5-grammar-mada-not-yet',
        'n5-grammar-toki-ni-when-basic',
        'n5-grammar-issho-ni-together',
        'n5-grammar-dake-only-basic',
        'n5-grammar-i-adj-adverbial-ku',
        'n5-grammar-na-adj-adverbial-ni',
        'n5-grammar-i-adj-te-joining-kute',
        'n5-grammar-na-adj-te-joining-de',
        'n5-grammar-mo-mo-both',
        'n5-grammar-to-exhaustive-listing',
        'n5-grammar-location-nouns-ue-shita',
        'n5-grammar-ikutsu-how-many',
        'n5-grammar-ikura-how-much',
        'n5-grammar-nanji-what-time',
        'n5-grammar-nanyoubi-day-of-week',
        'n5-grammar-counter-ji-oclock',
        'n5-grammar-counter-fun-minute',
        'n5-grammar-counter-sai-age',
        'n5-grammar-counter-en-money',
        'n5-grammar-counter-hon-long',
        'n5-grammar-counter-mai-flat',
        'n5-grammar-mai-every-prefix',
        'n5-grammar-jikan-time-duration',
        'n5-grammar-i-adj-desu-politeness',
        'n5-grammar-kara-cause',
    ];

    /**
     * @param  list<array{field: string, raw: string, normalized: string, tokens: list<array<string, string>>}>  $candidates
     * @return array<string, array{field: string, matchedText: string, rule: string}>
     */
    public function match(array $candidates): array
    {
        $matches = [];

        foreach ($candidates as $candidate) {
            $tokens = $this->significantTokens($candidate['tokens']);
            $text = $tokens === []
                ? $candidate['normalized']
                : implode('', array_column($tokens, 'normalizedSurface'));

            foreach (self::SUPPORTED_CONCEPT_IDS as $conceptId) {
                if (isset($matches[$conceptId]) || ! $this->matches($conceptId, $text, $tokens)) {
                    continue;
                }

                $matches[$conceptId] = [
                    'field' => $candidate['field'],
                    'matchedText' => $candidate['raw'],
                    'rule' => $conceptId,
                ];
            }
        }

        return $matches;
    }

    /** @param list<array<string, string>> $tokens */
    private function matches(string $conceptId, string $text, array $tokens): bool
    {
        return match ($conceptId) {
            'n5-grammar-desu-polite-copula' => $this->hasSuffixAfter($tokens, 'です', $this->isNoun(...)),
            'n5-grammar-da-plain-copula' => $this->hasSuffixAfter($tokens, 'だ', $this->isNoun(...)),
            'n5-grammar-deshita-past-polite-copula' => $this->hasSuffixAfter($tokens, 'でした', $this->isNoun(...)),
            'n5-grammar-dewa-arimasen-negative-polite-copula' => $this->hasSuffixAfter($tokens, 'ではありません', $this->isNoun(...)),
            'n5-grammar-masu-polite-verb' => $this->hasSuffixAfter($tokens, 'ます', $this->isVerb(...)),
            'n5-grammar-mashita-polite-past-verb' => $this->hasSuffixAfter($tokens, 'ました', $this->isVerb(...)),
            'n5-grammar-masen-polite-negative-verb' => $this->hasSuffixAfter($tokens, 'ません', $this->isVerb(...)),
            'n5-grammar-masendeshita-polite-past-negative-verb' => $this->hasSuffixAfter($tokens, 'ませんでした', $this->isVerb(...)),
            'n5-grammar-particle-wa-topic' => $this->hasParticle($tokens, 'は'),
            'n5-grammar-particle-ga-subject' => $this->hasCaseParticle($tokens, 'が'),
            'n5-grammar-particle-o-object' => $this->hasCaseParticle($tokens, 'を'),
            'n5-grammar-particle-ni-target' => $this->hasCaseParticle($tokens, 'に'),
            'n5-grammar-particle-de-means' => $this->hasCaseParticle($tokens, 'で'),
            'n5-grammar-particle-to-and-with' => $this->hasCaseParticle($tokens, 'と'),
            'n5-grammar-particle-no-possession' => $this->hasNounParticleNoun($tokens, 'の'),
            'n5-grammar-particle-e-direction' => $this->hasCaseParticle($tokens, 'へ'),
            'n5-grammar-particle-kara-from' => $this->hasParticleSubtype($tokens, 'から', '格助詞'),
            'n5-grammar-particle-made-until' => $this->hasParticle($tokens, 'まで'),
            'n5-grammar-particle-mo-also' => $this->hasParticle($tokens, 'も'),
            'n5-grammar-particle-ka-question' => $this->hasSentenceEndingParticle($tokens, 'か'),
            'n5-grammar-particle-ne-seeking-agreement' => $this->hasSentenceEndingParticle($tokens, 'ね'),
            'n5-grammar-particle-yo-emphasis' => $this->hasSentenceEndingParticle($tokens, 'よ'),
            'n5-grammar-kore-sore-are-demonstratives' => $this->containsAny($text, ['これ', 'それ', 'あれ', 'どれ']),
            'n5-grammar-kono-sono-ano-dono-attributive' => $this->containsAny($text, ['この', 'その', 'あの', 'どの']),
            'n5-grammar-koko-soko-asoko-doko' => $this->containsAny($text, ['ここ', 'そこ', 'あそこ', 'どこ']),
            'n5-grammar-arimasu-existence-inanimate' => str_contains($text, 'あります'),
            'n5-grammar-imasu-existence-animate' => $this->containsAny($text, ['がいます', 'にいます']) && ! $this->containsAny($text, ['ています', 'でいます']),
            'n5-grammar-te-form-basic' => $this->hasVerbConnectingParticle($tokens),
            'n5-grammar-te-kudasai-request' => $this->containsAny($text, ['てください', 'でください']),
            'n5-grammar-te-imasu-progressive' => $this->containsAny($text, ['ています', 'ている', 'ていました', 'ていません', 'ていない', 'でいます', 'でいる', 'でいました', 'でいません', 'でいない']),
            'n5-grammar-nai-form' => $this->hasVerbAuxiliary($tokens, 'ない'),
            'n5-grammar-ta-form' => $this->hasVerbAuxiliary($tokens, 'た', 'だ'),
            'n5-grammar-i-adjective-nonpast' => $this->hasNonpastIAdjective($tokens),
            'n5-grammar-i-adjective-negative' => $this->hasIAdjectiveInflection($tokens, ['くない', 'くありません']),
            'n5-grammar-i-adjective-past' => $this->hasIAdjectiveInflection($tokens, ['かった', 'かったです']),
            'n5-grammar-na-adjective-nonpast' => $this->hasSuffixAfter($tokens, 'です', $this->isNaAdjective(...))
                || $this->hasSuffixAfter($tokens, 'だ', $this->isNaAdjective(...)),
            'n5-grammar-na-adjective-attributive' => $this->hasCategorySurfaceCategory($tokens, $this->isNaAdjective(...), 'な', $this->isNoun(...)),
            'n5-grammar-na-adjective-negative' => $this->hasSuffixAfter($tokens, 'ではありません', $this->isNaAdjective(...))
                || $this->hasSuffixAfter($tokens, 'じゃない', $this->isNaAdjective(...)),
            'n5-grammar-na-adjective-past' => $this->hasSuffixAfter($tokens, 'でした', $this->isNaAdjective(...))
                || $this->hasSuffixAfter($tokens, 'だった', $this->isNaAdjective(...)),
            'n5-grammar-mashou-volitional' => $this->hasSuffixAfter($tokens, 'ましょう', $this->isVerb(...)),
            'n5-grammar-mashou-ka-invitation' => $this->hasSuffixAfter($tokens, 'ましょうか', $this->isVerb(...)),
            'n5-grammar-tai-desire' => $this->hasVerbAuxiliary($tokens, 'たい'),
            'n5-grammar-ga-hoshii-wanting-thing' => $this->containsAny($text, ['がほしい', 'が欲しい']),
            'n5-grammar-hou-ga-comparative' => str_contains($text, 'のほうが') && str_contains($text, 'より'),
            'n5-grammar-ichiban-superlative' => str_contains($text, '一番'),
            'n5-grammar-counter-tsu' => preg_match('/(?:一|二|三|四|五|六|七|八|九|いく)つ/u', $text) === 1,
            'n5-grammar-counter-people-nin' => preg_match('/(?:一人|二人|[0-9０-９三四五六七八九十百何]+人)/u', $text) === 1,
            'n5-grammar-question-words-basic' => $this->containsAny($text, ['何', '誰', 'どこ', 'いつ', 'どう', 'どうして']),
            'n5-grammar-nai-de-kudasai' => str_contains($text, 'ないでください'),
            'n5-grammar-particle-ya-non-exhaustive' => $this->hasParticle($tokens, 'や'),
            'n5-grammar-masenka-invitation' => $this->hasSuffixAfter($tokens, 'ませんか', $this->isVerb(...)),
            'n5-grammar-mou-ta-already' => str_contains($text, 'もう') && $this->hasPastPredicate($tokens),
            'n5-grammar-mada-not-yet' => str_contains($text, 'まだ') && $this->containsAny($text, ['ていない', 'ている', 'でいない', 'でいる']),
            'n5-grammar-toki-ni-when-basic' => str_contains($text, 'とき'),
            'n5-grammar-issho-ni-together' => $this->containsAny($text, ['と一緒に', 'といっしょに']),
            'n5-grammar-dake-only-basic' => str_contains($text, 'だけ'),
            'n5-grammar-i-adj-adverbial-ku' => $this->hasIAdjectiveBeforeVerb($tokens),
            'n5-grammar-na-adj-adverbial-ni' => $this->hasCategorySurfaceCategory($tokens, $this->isNaAdjective(...), 'に', $this->isVerb(...)),
            'n5-grammar-i-adj-te-joining-kute' => $this->hasIAdjectiveInflection($tokens, ['くて']),
            'n5-grammar-na-adj-te-joining-de' => $this->hasCopularConnector($tokens),
            'n5-grammar-mo-mo-both' => $this->particleCount($tokens, 'も') >= 2,
            'n5-grammar-to-exhaustive-listing' => $this->hasNounParticleNoun($tokens, 'と'),
            'n5-grammar-location-nouns-ue-shita' => preg_match('/の(?:上|下|中|前|後ろ|横|隣|間)(?:に|で|へ)/u', $text) === 1,
            'n5-grammar-ikutsu-how-many' => str_contains($text, 'いくつ'),
            'n5-grammar-ikura-how-much' => str_contains($text, 'いくら'),
            'n5-grammar-nanji-what-time' => str_contains($text, '何時'),
            'n5-grammar-nanyoubi-day-of-week' => str_contains($text, '曜日'),
            'n5-grammar-counter-ji-oclock' => preg_match('/[0-9０-９一二三四五六七八九十何]+時(?!間)/u', $text) === 1,
            'n5-grammar-counter-fun-minute' => preg_match('/[0-9０-９一二三四五六七八九十何]+分/u', $text) === 1,
            'n5-grammar-counter-sai-age' => preg_match('/[0-9０-９一二三四五六七八九十何]+(?:歳|才)/u', $text) === 1,
            'n5-grammar-counter-en-money' => preg_match('/[0-9０-９一二三四五六七八九十百千万何]+円/u', $text) === 1,
            'n5-grammar-counter-hon-long' => preg_match('/[0-9０-９一二三四五六七八九十何]+本/u', $text) === 1,
            'n5-grammar-counter-mai-flat' => preg_match('/[0-9０-９一二三四五六七八九十何]+枚/u', $text) === 1,
            'n5-grammar-mai-every-prefix' => preg_match('/毎(?:日|朝|晩|夜|週|月|年)/u', $text) === 1,
            'n5-grammar-jikan-time-duration' => preg_match('/[0-9０-９一二三四五六七八九十何]+時間/u', $text) === 1,
            'n5-grammar-i-adj-desu-politeness' => $this->hasSuffixAfter($tokens, 'です', $this->isIAdjective(...)),
            'n5-grammar-kara-cause' => $this->hasParticleSubtype($tokens, 'から', '接続助詞'),
        };
    }

    /**
     * @param  list<array<string, string>>  $tokens
     * @return list<array<string, string>>
     */
    private function significantTokens(array $tokens): array
    {
        $result = [];

        foreach ($tokens as $token) {
            $surface = LearningConceptText::normalize($token['surface'] ?? '');
            $partOfSpeech = $token['partOfSpeech'] ?? '';

            if ($surface === '' || str_starts_with($partOfSpeech, '記号') || str_starts_with($partOfSpeech, '補助記号')) {
                continue;
            }

            $token['normalizedSurface'] = $surface;
            $result[] = $token;
        }

        return $result;
    }

    /** @param list<array<string, string>> $tokens */
    private function hasSuffixAfter(array $tokens, string $suffix, callable $category): bool
    {
        for ($index = 0; $index < count($tokens); $index++) {
            if (! $category($tokens[$index])) {
                continue;
            }

            $tail = implode('', array_column(array_slice($tokens, $index + 1), 'normalizedSurface'));

            if (str_starts_with($tail, $suffix)) {
                return true;
            }
        }

        return false;
    }

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
            if ($surface === 'で' && ($tokens[$index + 1]['normalizedSurface'] ?? '') === 'は') {
                continue;
            }

            $partOfSpeech = ($token['partOfSpeech'] ?? '').' '.($token['partOfSpeechSubtype'] ?? '');

            if ($partOfSpeech === ' ' || str_contains($partOfSpeech, '格助詞')) {
                return true;
            }
        }

        return false;
    }

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

            if ($features !== ' ') {
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

    /** @param list<array<string, string>> $tokens */
    private function hasNounParticleNoun(array $tokens, string $surface): bool
    {
        for ($index = 1; $index < count($tokens) - 1; $index++) {
            if (($tokens[$index]['normalizedSurface'] ?? '') === $surface
                && $this->isParticle($tokens[$index])
                && $this->isNoun($tokens[$index - 1])
                && $this->isNoun($tokens[$index + 1])
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function hasVerbConnectingParticle(array $tokens): bool
    {
        for ($index = 1; $index < count($tokens); $index++) {
            if (in_array($tokens[$index]['normalizedSurface'] ?? '', ['て', 'で'], true)
                && $this->isParticle($tokens[$index])
                && $this->isVerb($tokens[$index - 1])
            ) {
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

            if ((in_array($surface, $surfaces, true) || in_array($base, $surfaces, true))
                && $this->isVerb($tokens[$index - 1])
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function hasNonpastIAdjective(array $tokens): bool
    {
        foreach ($tokens as $index => $token) {
            if (! $this->isIAdjective($token)) {
                continue;
            }

            $form = $token['conjugationForm'] ?? '';
            $next = $tokens[$index + 1]['normalizedSurface'] ?? '';

            if ($form === '' || str_contains($form, '終止') || str_contains($form, '基本') || $next === 'です') {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function hasIAdjectiveInflection(array $tokens, array $suffixes): bool
    {
        foreach ($tokens as $index => $token) {
            if (! $this->isIAdjective($token)) {
                continue;
            }

            $tail = implode('', array_column(array_slice($tokens, $index), 'normalizedSurface'));

            foreach ($suffixes as $suffix) {
                if (str_contains($tail, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function hasCategorySurfaceCategory(array $tokens, callable $before, string $surface, callable $after): bool
    {
        for ($index = 1; $index < count($tokens) - 1; $index++) {
            if (($tokens[$index]['normalizedSurface'] ?? '') === $surface
                && $before($tokens[$index - 1])
                && $after($tokens[$index + 1])
            ) {
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

    /** @param list<array<string, string>> $tokens */
    private function hasIAdjectiveBeforeVerb(array $tokens): bool
    {
        for ($index = 0; $index < count($tokens) - 1; $index++) {
            if ($this->isIAdjective($tokens[$index])
                && str_ends_with($tokens[$index]['normalizedSurface'] ?? '', 'く')
                && $this->isVerb($tokens[$index + 1])
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $tokens */
    private function hasCopularConnector(array $tokens): bool
    {
        for ($index = 1; $index < count($tokens); $index++) {
            if (($tokens[$index]['normalizedSurface'] ?? '') === 'で'
                && $this->isAuxiliary($tokens[$index])
                && ($this->isNaAdjective($tokens[$index - 1]) || $this->isNoun($tokens[$index - 1]))
            ) {
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

    /** @param list<string> $needles */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
