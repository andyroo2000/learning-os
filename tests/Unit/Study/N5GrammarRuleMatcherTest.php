<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\LearningConceptText;
use App\Domain\Study\Support\N5GrammarRuleMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class N5GrammarRuleMatcherTest extends TestCase
{
    public function test_supported_rules_exactly_cover_the_versioned_n5_grammar_catalog(): void
    {
        $handle = fopen(LEARNING_OS_PROJECT_ROOT.'/resources/jlpt/v1/n5-grammar.csv', 'rb');
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
        sort($catalogIds);
        $supportedIds = N5GrammarRuleMatcher::SUPPORTED_CONCEPT_IDS;
        sort($supportedIds);

        $this->assertSame($catalogIds, $supportedIds);
    }

    public function test_it_skips_grammar_classification_without_tokenizer_output(): void
    {
        $text = '学生です。もう帰りました。';

        $matches = (new N5GrammarRuleMatcher)->match([[
            'field' => 'frontText',
            'raw' => $text,
            'normalized' => LearningConceptText::normalize($text),
            'tokens' => [],
        ]]);

        $this->assertSame([], $matches);
    }

    /**
     * @param  list<array{string, string, string, string?: string, string?: string}>  $tokenRows
     * @param  list<string>  $expected
     * @param  list<string>  $unexpected
     */
    #[DataProvider('grammarExamples')]
    public function test_it_classifies_structural_grammar_without_fanning_out_shared_surfaces(
        string $text,
        array $tokenRows,
        array $expected,
        array $unexpected = [],
    ): void {
        $tokens = array_map(
            fn (array $row): array => [
                'surface' => $row[0],
                'base' => $row[1],
                'partOfSpeech' => $row[2],
                'partOfSpeechSubtype' => $row[3] ?? '',
                'conjugationType' => '',
                'conjugationForm' => $row[4] ?? '',
            ],
            $tokenRows,
        );

        $matches = (new N5GrammarRuleMatcher)->match([[
            'field' => 'frontText',
            'raw' => $text,
            'normalized' => LearningConceptText::normalize($text),
            'tokens' => $tokens,
        ]]);

        foreach ($expected as $conceptId) {
            $this->assertArrayHasKey($conceptId, $matches, "Expected {$conceptId} for {$text}");
        }

        foreach ($unexpected as $conceptId) {
            $this->assertArrayNotHasKey($conceptId, $matches, "Did not expect {$conceptId} for {$text}");
        }
    }

    /** @return array<string, array{string, list<array{string, string, string, string?: string, string?: string}>, list<string>, list<string>?}> */
    public static function grammarExamples(): array
    {
        return [
            'noun copula' => [
                '学生です。',
                [['学生', '学生', '名詞-普通名詞-一般'], ['です', 'です', '助動詞']],
                ['n5-grammar-desu-polite-copula'],
                ['n5-grammar-i-adj-desu-politeness', 'n5-grammar-na-adjective-nonpast'],
            ],
            'suffix must align to complete tokens' => [
                '友達だいすき。学生です。',
                [
                    ['友達', '友達', '名詞-普通名詞-一般'], ['だいすき', '大好き', '形状詞-一般'], ['。', '。', '補助記号-句点'],
                    ['学生', '学生', '名詞-普通名詞-一般'], ['です', 'です', '助動詞'], ['。', '。', '補助記号-句点'],
                ],
                ['n5-grammar-desu-polite-copula'],
                ['n5-grammar-da-plain-copula'],
            ],
            'noun copula variants' => [
                '学生だ。学生でした。学生ではありません。',
                [
                    ['学生', '学生', '名詞-普通名詞-一般'], ['だ', 'だ', '助動詞'],
                    ['学生', '学生', '名詞-普通名詞-一般'], ['でし', 'です', '助動詞'], ['た', 'た', '助動詞'],
                    ['学生', '学生', '名詞-普通名詞-一般'], ['で', 'で', '助詞-格助詞'], ['は', 'は', '助詞-係助詞'],
                    ['あり', '有る', '動詞-非自立可能'], ['ませ', 'ます', '助動詞'], ['ん', 'ぬ', '助動詞'],
                ],
                [
                    'n5-grammar-da-plain-copula',
                    'n5-grammar-deshita-past-polite-copula',
                    'n5-grammar-dewa-arimasen-negative-polite-copula',
                ],
                ['n5-grammar-particle-de-means'],
            ],
            'i adjective predicate' => [
                '高いです。',
                [['高い', '高い', '形容詞-一般', '', '終止形-一般'], ['です', 'です', '助動詞']],
                ['n5-grammar-i-adjective-nonpast', 'n5-grammar-i-adj-desu-politeness'],
                ['n5-grammar-desu-polite-copula', 'n5-grammar-na-adjective-nonpast'],
            ],
            'na adjective predicate' => [
                '静かです。',
                [['静か', '静か', '形状詞-一般'], ['です', 'です', '助動詞']],
                ['n5-grammar-na-adjective-nonpast'],
                ['n5-grammar-desu-polite-copula', 'n5-grammar-i-adj-desu-politeness'],
            ],
            'animate existence' => [
                '猫がいます。',
                [['猫', '猫', '名詞-普通名詞-一般'], ['が', 'が', '助詞-格助詞'], ['い', '居る', '動詞-非自立可能'], ['ます', 'ます', '助動詞']],
                ['n5-grammar-imasu-existence-animate', 'n5-grammar-particle-ga-subject'],
                ['n5-grammar-te-imasu-progressive'],
            ],
            'progressive' => [
                '本を読んでいます。',
                [['本', '本', '名詞-普通名詞-一般'], ['を', 'を', '助詞-格助詞'], ['読ん', '読む', '動詞-一般'], ['で', 'て', '助詞-接続助詞'], ['い', '居る', '動詞-非自立可能'], ['ます', 'ます', '助動詞']],
                ['n5-grammar-te-form-basic', 'n5-grammar-te-imasu-progressive', 'n5-grammar-particle-o-object'],
                ['n5-grammar-imasu-existence-animate'],
            ],
            'kara from' => [
                '家から来ます。',
                [['家', '家', '名詞-普通名詞-一般'], ['から', 'から', '助詞-格助詞'], ['来', '来る', '動詞-一般'], ['ます', 'ます', '助動詞']],
                ['n5-grammar-particle-kara-from'],
                ['n5-grammar-kara-cause'],
            ],
            'kara because' => [
                '寒いから帰ります。',
                [['寒い', '寒い', '形容詞-一般'], ['から', 'から', '助詞-接続助詞'], ['帰り', '帰る', '動詞-一般'], ['ます', 'ます', '助動詞']],
                ['n5-grammar-kara-cause'],
                ['n5-grammar-particle-kara-from'],
            ],
            'polite past negative' => [
                '読みませんでした。',
                [['読み', '読む', '動詞-一般'], ['ませ', 'ます', '助動詞'], ['ん', 'ぬ', '助動詞'], ['でし', 'です', '助動詞'], ['た', 'た', '助動詞']],
                ['n5-grammar-masendeshita-polite-past-negative-verb'],
            ],
            'plain negative' => [
                '読まない。',
                [['読ま', '読む', '動詞-一般'], ['ない', 'ない', '助動詞']],
                ['n5-grammar-nai-form'],
            ],
            'plain past' => [
                '読んだ。',
                [['読ん', '読む', '動詞-一般'], ['だ', 'た', '助動詞']],
                ['n5-grammar-ta-form'],
            ],
            'adjacent tokens do not create mou' => [
                '友達もうちに来ました。',
                [
                    ['友達', '友達', '名詞-普通名詞-一般'], ['も', 'も', '助詞-係助詞'], ['うち', 'うち', '名詞-普通名詞-一般'],
                    ['に', 'に', '助詞-格助詞'], ['来', '来る', '動詞-一般'], ['まし', 'ます', '助動詞'], ['た', 'た', '助動詞'],
                ],
                ['n5-grammar-mashita-polite-past-verb'],
                ['n5-grammar-mou-ta-already'],
            ],
            'i adjective negative and past' => [
                '高くない。安かった。',
                [
                    ['高く', '高い', '形容詞-一般', '', '連用形-一般'], ['ない', '無い', '形容詞-非自立可能'],
                    ['安かっ', '安い', '形容詞-一般', '', '連用形-促音便'], ['た', 'た', '助動詞'],
                ],
                ['n5-grammar-i-adjective-negative', 'n5-grammar-i-adjective-past'],
                ['n5-grammar-nai-form', 'n5-grammar-ta-form'],
            ],
            'adjective adverb' => [
                '早く走ります。',
                [['早く', '早い', '形容詞-一般', '', '連用形-一般'], ['走り', '走る', '動詞-一般'], ['ます', 'ます', '助動詞']],
                ['n5-grammar-i-adj-adverbial-ku'],
            ],
            'na adjective forms' => [
                '静かに話して、静かな町で休みます。',
                [
                    ['静か', '静か', '形状詞-一般'], ['に', 'だ', '助動詞'], ['話し', '話す', '動詞-一般'], ['て', 'て', '助詞-接続助詞'],
                    ['静か', '静か', '形状詞-一般'], ['な', 'だ', '助動詞'], ['町', '町', '名詞-普通名詞-一般'], ['で', 'で', '助詞-格助詞'],
                    ['休み', '休む', '動詞-一般'], ['ます', 'ます', '助動詞'],
                ],
                ['n5-grammar-na-adj-adverbial-ni', 'n5-grammar-na-adjective-attributive'],
            ],
            'copular connector' => [
                '静かで便利です。',
                [['静か', '静か', '形状詞-一般'], ['で', 'だ', '助動詞'], ['便利', '便利', '形状詞-一般'], ['です', 'です', '助動詞']],
                ['n5-grammar-na-adj-te-joining-de'],
                ['n5-grammar-particle-de-means'],
            ],
            'noun structures and location' => [
                '机の上に本と鉛筆があります。',
                [
                    ['机', '机', '名詞-普通名詞-一般'], ['の', 'の', '助詞-格助詞'], ['上', '上', '名詞-普通名詞-一般'], ['に', 'に', '助詞-格助詞'],
                    ['本', '本', '名詞-普通名詞-一般'], ['と', 'と', '助詞-格助詞'], ['鉛筆', '鉛筆', '名詞-普通名詞-一般'], ['が', 'が', '助詞-格助詞'],
                    ['あり', '有る', '動詞-非自立可能'], ['ます', 'ます', '助動詞'],
                ],
                ['n5-grammar-particle-no-possession', 'n5-grammar-to-exhaustive-listing', 'n5-grammar-location-nouns-ue-shita'],
            ],
            'particles' => [
                '私は学校へ友達と行きますよ。',
                [
                    ['私', '私', '代名詞'], ['は', 'は', '助詞-係助詞'], ['学校', '学校', '名詞-普通名詞-一般'], ['へ', 'へ', '助詞-格助詞'],
                    ['友達', '友達', '名詞-普通名詞-一般'], ['と', 'と', '助詞-格助詞'], ['行き', '行く', '動詞-一般'], ['ます', 'ます', '助動詞'], ['よ', 'よ', '助詞-終助詞'],
                ],
                ['n5-grammar-particle-wa-topic', 'n5-grammar-particle-e-direction', 'n5-grammar-particle-to-and-with', 'n5-grammar-particle-yo-emphasis'],
            ],
            'remaining particles' => [
                'これもいいですね。行きますか。',
                [
                    ['これ', 'これ', '代名詞'], ['も', 'も', '助詞-係助詞'], ['いい', '良い', '形容詞-一般'], ['です', 'です', '助動詞'], ['ね', 'ね', '助詞-終助詞'],
                    ['行き', '行く', '動詞-一般'], ['ます', 'ます', '助動詞'], ['か', 'か', '助詞-終助詞'],
                ],
                ['n5-grammar-particle-mo-also', 'n5-grammar-particle-ne-seeking-agreement', 'n5-grammar-particle-ka-question'],
            ],
            'both nouns' => [
                '母も父も先生です。',
                [
                    ['母', '母', '名詞-普通名詞-一般'], ['も', 'も', '助詞-係助詞'], ['父', '父', '名詞-普通名詞-一般'],
                    ['も', 'も', '助詞-係助詞'], ['先生', '先生', '名詞-普通名詞-一般'], ['です', 'です', '助動詞'],
                ],
                ['n5-grammar-mo-mo-both'],
            ],
            'counters' => [
                '三人で二時間、三本を五百円で買いました。',
                [
                    ['三', '三', '名詞-数詞'], ['人', '人', '接尾辞-名詞的-助数詞'], ['で', 'で', '助詞-格助詞'], ['二', '二', '名詞-数詞'], ['時間', '時間', '名詞-普通名詞-助数詞可能'],
                    ['三', '三', '名詞-数詞'], ['本', '本', '接尾辞-名詞的-助数詞'], ['を', 'を', '助詞-格助詞'], ['五百', '五百', '名詞-数詞'], ['円', '円', '名詞-普通名詞-助数詞可能'],
                    ['で', 'で', '助詞-格助詞'], ['買い', '買う', '動詞-一般'], ['まし', 'ます', '助動詞'], ['た', 'た', '助動詞'],
                ],
                ['n5-grammar-counter-people-nin', 'n5-grammar-jikan-time-duration', 'n5-grammar-counter-hon-long', 'n5-grammar-counter-en-money'],
            ],
            'remaining counters and every prefix' => [
                '毎日、三時五分に二枚と一つを買います。私は二十歳です。',
                [
                    ['毎日', '毎日', '名詞-普通名詞-副詞可能'], ['三', '三', '名詞-数詞'], ['時', '時', '接尾辞-名詞的-助数詞'],
                    ['五', '五', '名詞-数詞'], ['分', '分', '接尾辞-名詞的-助数詞'], ['に', 'に', '助詞-格助詞'],
                    ['二', '二', '名詞-数詞'], ['枚', '枚', '接尾辞-名詞的-助数詞'], ['と', 'と', '助詞-格助詞'], ['一つ', '一つ', '名詞-普通名詞-一般'],
                    ['を', 'を', '助詞-格助詞'], ['買い', '買う', '動詞-一般'], ['ます', 'ます', '助動詞'],
                    ['私', '私', '代名詞'], ['は', 'は', '助詞-係助詞'], ['二十', '二十', '名詞-数詞'], ['歳', '歳', '接尾辞-名詞的-助数詞'], ['です', 'です', '助動詞'],
                ],
                [
                    'n5-grammar-mai-every-prefix',
                    'n5-grammar-counter-ji-oclock',
                    'n5-grammar-counter-fun-minute',
                    'n5-grammar-counter-mai-flat',
                    'n5-grammar-counter-tsu',
                    'n5-grammar-counter-sai-age',
                ],
            ],
            'time questions and toki' => [
                '何時ですか。何曜日ですか。学生の時に勉強しました。',
                [
                    ['何', '何', '名詞-数詞'], ['時', '時', '名詞-普通名詞-助数詞可能'], ['です', 'です', '助動詞'], ['か', 'か', '助詞-終助詞'], ['。', '。', '補助記号-句点'],
                    ['何曜', '何曜', '名詞-普通名詞-一般'], ['日', '日', '名詞-普通名詞-副詞可能'], ['です', 'です', '助動詞'], ['か', 'か', '助詞-終助詞'], ['。', '。', '補助記号-句点'],
                    ['学生', '学生', '名詞-普通名詞-一般'], ['の', 'の', '助詞-格助詞'], ['時', '時', '名詞-普通名詞-副詞可能'], ['に', 'に', '助詞-格助詞'],
                    ['勉強', '勉強', '名詞-普通名詞-サ変可能'], ['し', '為る', '動詞-非自立可能'], ['まし', 'ます', '助動詞'], ['た', 'た', '助動詞'],
                ],
                ['n5-grammar-nanji-what-time', 'n5-grammar-nanyoubi-day-of-week', 'n5-grammar-toki-ni-when-basic'],
            ],
            'clock time is not toki' => [
                '三時に行きます。',
                [
                    ['三', '三', '名詞-数詞'], ['時', '時', '名詞-普通名詞-助数詞可能'], ['に', 'に', '助詞-格助詞'],
                    ['行き', '行く', '動詞-一般'], ['ます', 'ます', '助動詞'],
                ],
                ['n5-grammar-counter-ji-oclock'],
                ['n5-grammar-toki-ni-when-basic'],
            ],
        ];
    }
}
