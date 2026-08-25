<?php

namespace App\Domain\Study\Support;

final class N4GrammarRuleMatcher
{
    /**
     * These are deliberately high-signal surface constructions. Form-only rules
     * (potential, passive, causative, conditionals, and similar morphology) stay
     * unmatched until they can be classified without inflating the estimate.
     *
     * @var array<string, list<string>>
     */
    public const RULES = [
        'n4-grammar-ta-koto-ga-aru' => ['たことがある'],
        'n4-grammar-tsumori-intention' => ['つもり'],
        'n4-grammar-to-omou' => ['と思う', 'と思います'],
        'n4-grammar-to-iu' => ['と言う', 'という', 'と言います'],
        'n4-grammar-darou-deshou-conjecture' => ['だろう', 'でしょう'],
        'n4-grammar-kamoshirenai-might' => ['かもしれない', 'かもしれません'],
        'n4-grammar-hazu-expected' => ['はず'],
        'n4-grammar-te-wa-ikenai-prohibition' => ['てはいけない', 'ではいけない', 'てはいけません', 'ではいけません'],
        'n4-grammar-te-mo-ii-permission' => ['てもいい', 'でもいい', 'てもいいです', 'でもいいです'],
        'n4-grammar-nakereba-naranai' => ['なければならない', 'なければなりません'],
        'n4-grammar-nakute-mo-ii' => ['なくてもいい', 'なくてもいいです'],
        'n4-grammar-node-cause' => ['ので'],
        'n4-grammar-ga-kedo-although' => ['けど', 'けれど', 'けれども'],
        'n4-grammar-n-desu-explanation' => ['んです', 'のです'],
        'n4-grammar-ni-naru-become' => ['になる'],
        'n4-grammar-ni-suru-decide' => ['にする'],
        'n4-grammar-tari-tari-suru' => ['たり', 'だり'],
        'n4-grammar-you-ni-suru' => ['ようにする', 'ようにしている', 'ようにしています'],
        'n4-grammar-te-kara-after-doing' => ['てから', 'でから'],
        'n4-grammar-koto-ga-dekiru' => ['ことができる', 'ことができます'],
        'n4-grammar-koto-ni-suru-decide' => ['ことにする', 'ことにします'],
        'n4-grammar-koto-ni-naru-be-decided' => ['ことになる', 'ことになりました', 'ことになります'],
        'n4-grammar-nasai-command' => ['なさい'],
        'n4-grammar-te-hoshii-want-someone' => ['てほしい', 'でほしい', 'て欲しい', 'で欲しい'],
        'n4-grammar-te-kureru-favor-received' => ['てくれる', 'でくれる', 'てくれます', 'でくれます'],
        'n4-grammar-te-morau-request-favor' => ['てもらう', 'でもらう', 'てもらいます', 'でもらいます'],
        'n4-grammar-te-ageru-favor-given' => ['てあげる', 'であげる', 'てあげます', 'であげます'],
        'n4-grammar-sou-iu-kou-iu' => ['こういう', 'そういう', 'ああいう', 'どういう'],
        'n4-grammar-tai-to-omou-want-to-think' => ['たいと思う', 'たいと思います'],
        'n4-grammar-ba-ai-in-case' => ['場合', '場合は'],
        'n4-grammar-zutsu-each-per' => ['ずつ'],
        'n4-grammar-tsumori-datta-had-intended' => ['つもりだった', 'つもりでした'],
        'n4-grammar-hazu-datta-was-supposed' => ['はずだった', 'はずでした'],
        'n4-grammar-te-itadaku-polite-favor-received' => ['ていただく', 'でいただく', 'ていただきます', 'でいただきます'],
        'n4-grammar-irassharu-honorific' => ['いらっしゃる', 'いらっしゃいます'],
        'n4-grammar-ossharu-honorific' => ['おっしゃる', 'おっしゃいます'],
        'n4-grammar-goran-ni-naru-honorific' => ['ご覧になる', 'ご覧になります'],
        'n4-grammar-nasaru-honorific' => ['なさる', 'なさいます'],
        'n4-grammar-mairu-humble' => ['参る', '参ります'],
        'n4-grammar-mousu-humble' => ['申す', '申します'],
        'n4-grammar-itadaku-humble-verb' => ['いただく', 'いただきます'],
        'n4-grammar-kudasaru-honorific-verb' => ['くださる', 'くださいます'],
        'n4-grammar-ga-suki-kirai' => ['が好き', 'が嫌い'],
        'n4-grammar-ga-wakaru' => ['がわかる', 'が分かる', 'がわかります', 'が分かります'],
        'n4-grammar-ga-dekiru' => ['ができる', 'ができます'],
        'n4-grammar-ga-kikoeru-mieru' => ['が聞こえる', 'が見える', 'が聞こえます', 'が見えます'],
        'n4-grammar-dakara-so' => ['だから'],
        'n4-grammar-sorede-and-then' => ['それで'],
        'n4-grammar-sorekara-after' => ['それから'],
        'n4-grammar-shikashi-but' => ['しかし'],
        'n4-grammar-kedomo-formal-but' => ['けれども'],
        'n4-grammar-toka-listing' => ['とか'],
        'n4-grammar-soredemo-even-so' => ['それでも'],
        'n4-grammar-you-ka-to-omou' => ['かと思う', 'かと思います'],
    ];

    /** @var array<string, list<string>> */
    private const SUPPRESSED_BY_MORE_SPECIFIC_RULE = [
        'n4-grammar-tsumori-datta-had-intended' => ['n4-grammar-tsumori-intention'],
        'n4-grammar-hazu-datta-was-supposed' => ['n4-grammar-hazu-expected'],
        'n4-grammar-nakute-mo-ii' => ['n4-grammar-te-mo-ii-permission'],
        'n4-grammar-koto-ni-suru-decide' => ['n4-grammar-ni-suru-decide'],
        'n4-grammar-koto-ni-naru-be-decided' => ['n4-grammar-ni-naru-become'],
        'n4-grammar-you-ni-suru' => ['n4-grammar-ni-suru-decide'],
        'n4-grammar-tai-to-omou-want-to-think' => ['n4-grammar-to-omou'],
        'n4-grammar-te-itadaku-polite-favor-received' => ['n4-grammar-itadaku-humble-verb'],
        'n4-grammar-goran-ni-naru-honorific' => ['n4-grammar-ni-naru-become'],
        'n4-grammar-kedomo-formal-but' => ['n4-grammar-ga-kedo-although'],
        'n4-grammar-you-ka-to-omou' => ['n4-grammar-to-omou'],
    ];

    /**
     * @param  list<array{field: string, raw: string, normalized: string, tokens: list<array<string, string>>}>  $candidates
     * @return array<string, array{field: string, matchedText: string, rule: string, surface: string}>
     */
    public function match(array $candidates): array
    {
        $matches = [];

        foreach ($candidates as $candidate) {
            foreach ($this->segments($candidate['tokens']) as $segment) {
                foreach (self::RULES as $conceptId => $surfaces) {
                    if (isset($matches[$conceptId])) {
                        continue;
                    }

                    foreach ($surfaces as $surface) {
                        if (! str_contains($segment, LearningConceptText::normalize($surface))) {
                            continue;
                        }

                        // たり〜たり / だり〜だり is defined by repetition rather
                        // than one incidental connecting-particle occurrence.
                        if ($conceptId === 'n4-grammar-tari-tari-suru'
                            && substr_count($segment, 'たり') + substr_count($segment, 'だり') < 2
                        ) {
                            continue;
                        }

                        $matches[$conceptId] = [
                            'field' => $candidate['field'],
                            'matchedText' => $candidate['raw'],
                            'rule' => $conceptId,
                            'surface' => $surface,
                        ];

                        break;
                    }
                }
            }
        }

        foreach (self::SUPPRESSED_BY_MORE_SPECIFIC_RULE as $specific => $generalRules) {
            if (! isset($matches[$specific])) {
                continue;
            }

            foreach ($generalRules as $general) {
                unset($matches[$general]);
            }
        }

        return $matches;
    }

    /** @param list<array<string, string>> $tokens */
    private function segments(array $tokens): array
    {
        $segments = [];
        $segment = '';

        foreach ($tokens as $token) {
            $surface = LearningConceptText::normalize($token['surface'] ?? '');
            $partOfSpeech = $token['partOfSpeech'] ?? '';

            if ($surface === '' || str_starts_with($partOfSpeech, '記号') || str_starts_with($partOfSpeech, '補助記号')) {
                if ($segment !== '') {
                    $segments[] = $segment;
                    $segment = '';
                }

                continue;
            }

            $segment .= $surface;
        }

        if ($segment !== '') {
            $segments[] = $segment;
        }

        return $segments;
    }
}
