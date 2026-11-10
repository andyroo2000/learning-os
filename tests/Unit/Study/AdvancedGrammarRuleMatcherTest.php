<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\AdvancedGrammarRuleMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdvancedGrammarRuleMatcherTest extends TestCase
{
    public function test_rules_reference_catalog_patterns_and_fit_the_phrase_bound(): void
    {
        $ids = [];
        foreach ([3, 2, 1] as $level) {
            $handle = fopen(LEARNING_OS_PROJECT_ROOT.'/resources/jlpt/v1/n'.$level.'-grammar.csv', 'rb');
            $header = fgetcsv($handle, escape: '');
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                $ids[] = array_combine($header, $values)['concept_id'];
            }
            fclose($handle);
        }
        $this->assertSame([], array_diff(array_keys(AdvancedGrammarRuleMatcher::RULES), $ids));
        foreach (AdvancedGrammarRuleMatcher::RULES as $surfaces) {
            foreach ($surfaces as $surface) {
                $this->assertLessThanOrEqual(16, mb_strlen($surface));
            }
        }
    }

    #[DataProvider('examples')]
    public function test_phrases_require_complete_adjacent_tokens(array $surfaces, ?string $expected = null): void
    {
        $tokens = array_map(fn (string $surface): array => [
            'surface' => $surface,
            'partOfSpeech' => $surface === '。' ? '補助記号-句点' : '名詞',
        ], $surfaces);
        $matches = (new AdvancedGrammarRuleMatcher)->match([[
            'field' => 'frontText', 'raw' => implode('', $surfaces), 'tokens' => $tokens,
        ]]);
        $this->assertSame($expected === null ? [] : [$expected], array_keys($matches));
    }

    public static function examples(): array
    {
        return [
            'N3 about' => [['日本', 'に', 'つい', 'て', '話す'], 'n3-grammar-ni-tsuite'],
            'N2 regardless' => [['年齢', 'を', '問わ', 'ず', '参加'], 'n2-grammar-wo-towazu-regardless'],
            'N1 contrary to expectation' => [['晴れる', 'と', '思い', 'きや', '雨'], 'n1-grammar-to-omoi-kiya'],
            'N3 polite negative' => [['そう', 'と', 'は', '限り', 'ませ', 'ん'], 'n3-grammar-to-wa-kagiranai'],
            'no substring in a token' => [['架空について語']],
            'no punctuation crossing' => [['に', '。', 'つい', 'て']],
            'no blank crossing' => [['を', ' ', '問わ', 'ず']],
            'no missing token' => [['と', '思い', '雨']],
        ];
    }
}
