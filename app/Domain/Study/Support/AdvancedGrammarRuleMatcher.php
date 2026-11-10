<?php

namespace App\Domain\Study\Support;

final class AdvancedGrammarRuleMatcher
{
    // Only distinctive fixed phrases; morphology and ambiguous short fragments
    // remain in the denominator without receiving speculative automatic credit.
    public const RULES = [
        'n3-grammar-ka-dou-ka' => ['かどうか'],
        'n3-grammar-ni-tsuite' => ['について'],
        'n3-grammar-ni-totte' => ['にとって'],
        'n3-grammar-to-wa-kagiranai' => ['とは限らない', 'とは限りません'],
        'n3-grammar-ni-kanshite-regarding' => ['に関して', 'に関する'],
        'n3-grammar-ni-kurabete-compared' => ['に比べて'],
        'n3-grammar-wake-dewa-nai' => ['わけではない', 'わけではありません'],
        'n3-grammar-wake-ga-nai' => ['わけがない', 'わけがありません'],
        'n3-grammar-ni-chigainai-must' => ['にちがいない', 'に違いない'],
        'n3-grammar-ni-kimatte-iru' => ['に決まっている'],
        'n2-grammar-ni-mo-kakawarazu-despite' => ['にもかかわらず'],
        'n2-grammar-wo-towazu-regardless' => ['を問わず'],
        'n2-grammar-ni-tomonatte-accompany' => ['に伴って', 'に伴い'],
        'n2-grammar-ni-oujite-depending' => ['に応じて'],
        'n2-grammar-wo-keiki-ni' => ['を契機に', 'を契機として'],
        'n2-grammar-wo-komete' => ['を込めて'],
        'n2-grammar-wa-sate-oki' => ['はさておき'],
        'n2-grammar-no-minarazu' => ['のみならず'],
        'n2-grammar-ni-suginai' => ['にすぎない', 'に過ぎない'],
        'n2-grammar-zaru-wo-enai' => ['ざるを得ない', 'ざるを得ません'],
        'n1-grammar-ni-kataku-nai' => ['に難くない'],
        'n1-grammar-wo-yogi-naku-sareru' => ['を余儀なくされる', 'を余儀なくされた'],
        'n1-grammar-wo-kinjienai' => ['を禁じ得ない'],
        'n1-grammar-wo-mono-to-mo-sezu' => ['をものともせず'],
        'n1-grammar-ni-hoka-naranai' => ['に他ならない'],
        'n1-grammar-mo-saru-koto-nagara' => ['もさることながら'],
        'n1-grammar-ni-soui-nai' => ['に相違ない'],
        'n1-grammar-to-wa-urahara-ni' => ['とは裏腹に'],
        'n1-grammar-to-omoi-kiya' => ['と思いきや'],
        'n1-grammar-ni-kangamite' => ['に鑑みて'],
    ];

    /** @return array<string, array<string, string>> */
    public function match(array $candidates): array
    {
        $matches = [];
        foreach ($candidates as $candidate) {
            $phrases = $this->tokenPhrases($candidate['tokens']);
            foreach (self::RULES as $conceptId => $surfaces) {
                $surface = $this->matchingSurface($phrases, $surfaces);
                if ($surface !== null) {
                    $matches[$conceptId] = [
                        'field' => $candidate['field'],
                        'matchedText' => $candidate['raw'],
                        'rule' => $conceptId,
                        'surface' => $surface,
                    ];
                }
            }
        }

        return $matches;
    }

    private function matchingSurface(array $phrases, array $surfaces): ?string
    {
        foreach ($surfaces as $surface) {
            if (isset($phrases[LearningConceptText::normalize($surface)])) {
                return $surface;
            }
        }

        return null;
    }

    /** @return array<string, true> */
    private function tokenPhrases(array $tokens): array
    {
        $phrases = [];
        foreach (array_keys($tokens) as $start) {
            $this->appendPhrases($phrases, $tokens, $start);
        }

        return $phrases;
    }

    private function appendPhrases(array &$phrases, array $tokens, int $start): void
    {
        $phrase = '';
        for ($index = $start; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if ($this->isBoundary($token)) {
                break;
            }
            $phrase .= LearningConceptText::normalize($token['surface']);
            // All curated surfaces fit within this bound. Do not build unbounded
            // sentence substrings when processing long card answers.
            if (mb_strlen($phrase, 'UTF-8') > 16) {
                break;
            }
            $phrases[$phrase] = true;
        }
    }

    private function isBoundary(array $token): bool
    {
        $pos = $token['partOfSpeech'] ?? '';

        return LearningConceptText::normalize($token['surface'] ?? '') === ''
            || str_starts_with($pos, '記号')
            || str_starts_with($pos, '補助記号');
    }
}
