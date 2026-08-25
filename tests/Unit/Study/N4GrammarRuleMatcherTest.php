<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\LearningConceptText;
use App\Domain\Study\Support\N4GrammarRuleMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class N4GrammarRuleMatcherTest extends TestCase
{
    public function test_supported_rules_are_present_in_the_versioned_n4_catalog(): void
    {
        $handle = fopen(LEARNING_OS_PROJECT_ROOT.'/resources/jlpt/v1/n4-grammar.csv', 'rb');
        $this->assertIsResource($handle);
        $header = fgetcsv($handle, escape: '');
        $this->assertIsArray($header);
        $catalogIds = [];

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            $row = array_combine($header, $values);
            $this->assertIsArray($row);
            $catalogIds[] = $row['concept_id'];
        }

        fclose($handle);

        $this->assertSame([], array_diff(array_keys(N4GrammarRuleMatcher::RULES), $catalogIds));
    }

    #[DataProvider('surfaceExamples')]
    public function test_it_matches_conservative_surface_rules(
        string $text,
        array $tokens,
        array $expected,
        array $unexpected = [],
    ): void {
        $matches = (new N4GrammarRuleMatcher)->match([[
            'field' => 'frontText',
            'raw' => $text,
            'normalized' => LearningConceptText::normalize($text),
            'tokens' => array_map(
                fn (string $surface): array => [
                    'surface' => $surface,
                    'base' => $surface,
                    'partOfSpeech' => $surface === '。' ? '補助記号-句点' : '名詞-普通名詞-一般',
                ],
                $tokens,
            ),
        ]]);

        foreach ($expected as $conceptId) {
            $this->assertArrayHasKey($conceptId, $matches);
        }

        foreach ($unexpected as $conceptId) {
            $this->assertArrayNotHasKey($conceptId, $matches);
        }
    }

    public static function surfaceExamples(): array
    {
        return [
            'might' => [
                '雨かもしれない。',
                ['雨', 'かも', 'しれ', 'ない', '。'],
                ['n4-grammar-kamoshirenai-might'],
            ],
            'specific non-requirement suppresses general permission' => [
                '行かなくてもいい。',
                ['行か', 'なく', 'て', 'も', 'いい', '。'],
                ['n4-grammar-nakute-mo-ii'],
                ['n4-grammar-te-mo-ii-permission'],
            ],
            'specific past intention suppresses general intention' => [
                '行くつもりだった。',
                ['行く', 'つもり', 'だっ', 'た', '。'],
                ['n4-grammar-tsumori-datta-had-intended'],
                ['n4-grammar-tsumori-intention'],
            ],
            'tari requires repetition in one sentence segment' => [
                '読んだり書いたりする。',
                ['読ん', 'だり', '書い', 'たり', 'する', '。'],
                ['n4-grammar-tari-tari-suru'],
            ],
            'one tari is not enough' => [
                '読んだりする。',
                ['読ん', 'だり', 'する', '。'],
                [],
                ['n4-grammar-tari-tari-suru'],
            ],
            'surface rules do not cross punctuation' => [
                '行って。から始める。',
                ['行っ', 'て', '。', 'から', '始める', '。'],
                [],
                ['n4-grammar-te-kara-after-doing'],
            ],
            'ambiguous nara and tte fragments are not credited' => [
                '持っていかなければならない。',
                ['持っ', 'て', 'いか', 'なけれ', 'ば', 'なら', 'ない', '。'],
                ['n4-grammar-nakereba-naranai'],
                ['n4-grammar-nara-conditional', 'n4-grammar-tte-quotation'],
            ],
        ];
    }
}
