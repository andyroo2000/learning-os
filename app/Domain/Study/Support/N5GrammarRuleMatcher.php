<?php

namespace App\Domain\Study\Support;

final class N5GrammarRuleMatcher
{
    use Concerns\MatchesN5GrammarAdjectiveConnectors;
    use Concerns\MatchesN5GrammarAdjectiveInflections;
    use Concerns\MatchesN5GrammarCategoryLinks;
    use Concerns\MatchesN5GrammarLexicalTokens;
    use Concerns\MatchesN5GrammarNonpastAdjectives;
    use Concerns\MatchesN5GrammarNounLinks;
    use Concerns\MatchesN5GrammarParticleKinds;
    use Concerns\MatchesN5GrammarParticles;
    use Concerns\MatchesN5GrammarPhrasesAndCounters;
    use Concerns\MatchesN5GrammarSurfacesAndLocations;
    use Concerns\MatchesN5GrammarTokiConstructions;
    use Concerns\MatchesN5GrammarVerbForms;

    /**
     * Every catalog ID must also have an explicit arm in matches(); the catalog
     * completeness test exercises this invariant whenever either list changes.
     *
     * @var list<string>
     */
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
            foreach ($this->significantTokenSegments($candidate['tokens']) as $tokens) {
                foreach (self::SUPPORTED_CONCEPT_IDS as $conceptId) {
                    if (isset($matches[$conceptId]) || ! $this->matches($conceptId, $tokens)) {
                        continue;
                    }

                    $matches[$conceptId] = [
                        'field' => $candidate['field'],
                        'matchedText' => $candidate['raw'],
                        'rule' => $conceptId,
                    ];
                }
            }
        }

        return $matches;
    }

    /** @param list<array<string, string>> $tokens */
    private function matches(string $conceptId, array $tokens): bool
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
            'n5-grammar-kore-sore-are-demonstratives' => $this->hasAnyTokenSurface($tokens, ['これ', 'それ', 'あれ', 'どれ']),
            'n5-grammar-kono-sono-ano-dono-attributive' => $this->hasAnyTokenSurface($tokens, ['この', 'その', 'あの', 'どの']),
            'n5-grammar-koko-soko-asoko-doko' => $this->hasAnyTokenSurface($tokens, ['ここ', 'そこ', 'あそこ', 'どこ']),
            'n5-grammar-arimasu-existence-inanimate' => $this->hasTokenPhrase($tokens, 'あります'),
            'n5-grammar-imasu-existence-animate' => $this->hasSurfaceBeforePhrase($tokens, ['が', 'に'], 'います'),
            'n5-grammar-te-form-basic' => $this->hasVerbConnectingParticle($tokens),
            'n5-grammar-te-kudasai-request' => $this->hasAnyTokenPhrase($tokens, ['てください', 'でください']),
            'n5-grammar-te-imasu-progressive' => $this->hasAnyTokenPhrase($tokens, ['ています', 'ている', 'ていました', 'ていません', 'ていない', 'でいます', 'でいる', 'でいました', 'でいません', 'でいない']),
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
            'n5-grammar-ga-hoshii-wanting-thing' => $this->hasAnyTokenPhrase($tokens, ['がほしい', 'が欲しい']),
            'n5-grammar-hou-ga-comparative' => $this->hasTokenPhrase($tokens, 'のほうが') && $this->hasTokenSurface($tokens, 'より'),
            'n5-grammar-ichiban-superlative' => $this->hasTokenSurface($tokens, '一番'),
            'n5-grammar-counter-tsu' => $this->hasNumberCounter($tokens, 'つ', '[0-9０-９一二三四五六七八九]'),
            'n5-grammar-counter-people-nin' => $this->hasNumberCounter($tokens, '人'),
            'n5-grammar-question-words-basic' => $this->hasAnyTokenSurface($tokens, ['何', '誰', 'どこ', 'いつ', 'どう', 'どうして', '何時', '何曜日']),
            'n5-grammar-nai-de-kudasai' => $this->hasTokenPhrase($tokens, 'ないでください'),
            'n5-grammar-particle-ya-non-exhaustive' => $this->hasParticle($tokens, 'や'),
            'n5-grammar-masenka-invitation' => $this->hasSuffixAfter($tokens, 'ませんか', $this->isVerb(...)),
            'n5-grammar-mou-ta-already' => $this->hasTokenSurface($tokens, 'もう') && $this->hasPastPredicate($tokens),
            'n5-grammar-mada-not-yet' => $this->hasTokenSurface($tokens, 'まだ') && $this->hasAnyTokenPhrase($tokens, ['ていない', 'ている', 'でいない', 'でいる']),
            'n5-grammar-toki-ni-when-basic' => $this->hasTokiConstruction($tokens),
            'n5-grammar-issho-ni-together' => $this->hasAnyTokenPhrase($tokens, ['と一緒に', 'といっしょに']),
            'n5-grammar-dake-only-basic' => $this->hasTokenSurface($tokens, 'だけ'),
            'n5-grammar-i-adj-adverbial-ku' => $this->hasIAdjectiveBeforeVerb($tokens),
            'n5-grammar-na-adj-adverbial-ni' => $this->hasCategorySurfaceCategory($tokens, $this->isNaAdjective(...), 'に', $this->isVerb(...)),
            'n5-grammar-i-adj-te-joining-kute' => $this->hasIAdjectiveInflection($tokens, ['くて']),
            'n5-grammar-na-adj-te-joining-de' => $this->hasCopularConnector($tokens),
            'n5-grammar-mo-mo-both' => $this->particleCount($tokens, 'も') >= 2,
            'n5-grammar-to-exhaustive-listing' => $this->hasNounParticleNoun($tokens, 'と'),
            'n5-grammar-location-nouns-ue-shita' => $this->hasRelativeLocation($tokens),
            'n5-grammar-ikutsu-how-many' => $this->hasTokenSurface($tokens, 'いくつ'),
            'n5-grammar-ikura-how-much' => $this->hasTokenSurface($tokens, 'いくら'),
            'n5-grammar-nanji-what-time' => $this->hasTokenPhrase($tokens, '何時'),
            'n5-grammar-nanyoubi-day-of-week' => $this->hasAnyTokenPhrase($tokens, ['何曜日', '月曜日', '火曜日', '水曜日', '木曜日', '金曜日', '土曜日', '日曜日']),
            'n5-grammar-counter-ji-oclock' => $this->hasNumberCounter($tokens, '時'),
            'n5-grammar-counter-fun-minute' => $this->hasNumberCounter($tokens, '分'),
            'n5-grammar-counter-sai-age' => $this->hasAnyNumberCounter($tokens, ['歳', '才']),
            'n5-grammar-counter-en-money' => $this->hasNumberCounter($tokens, '円'),
            'n5-grammar-counter-hon-long' => $this->hasNumberCounter($tokens, '本'),
            'n5-grammar-counter-mai-flat' => $this->hasNumberCounter($tokens, '枚'),
            'n5-grammar-mai-every-prefix' => $this->hasAnyTokenPhrase($tokens, ['毎日', '毎朝', '毎晩', '毎夜', '毎週', '毎月', '毎年']),
            'n5-grammar-jikan-time-duration' => $this->hasNumberCounter($tokens, '時間'),
            'n5-grammar-i-adj-desu-politeness' => $this->hasSuffixAfter($tokens, 'です', $this->isIAdjective(...)),
            'n5-grammar-kara-cause' => $this->hasParticleSubtype($tokens, 'から', '接続助詞'),
        };
    }
}
