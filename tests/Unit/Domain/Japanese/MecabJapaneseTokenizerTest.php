<?php

namespace Tests\Unit\Domain\Japanese;

use App\Domain\Japanese\Services\MecabJapaneseTokenizer;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MecabJapaneseTokenizerTest extends TestCase
{
    public function test_it_parses_ipadic_surface_and_dictionary_forms_for_each_input(): void
    {
        $groups = (new MecabJapaneseTokenizer)->parseOutput(<<<'OUTPUT'
読み	動詞,自立,*,*,五段・マ行,連用形,読む,ヨミ,ヨミ
ました	助動詞,*,*,*,特殊・マス,連用形,ます,マシタ,マシタ
EOS
本	名詞,一般,*,*,*,*,本,ホン,ホン
EOS
OUTPUT, 2);

        $this->assertSame([
            [
                [
                    'surface' => '読み',
                    'base' => '読む',
                    'partOfSpeech' => '動詞',
                    'partOfSpeechSubtype' => '自立',
                    'conjugationType' => '五段・マ行',
                    'conjugationForm' => '連用形',
                ],
                [
                    'surface' => 'ました',
                    'base' => 'ます',
                    'partOfSpeech' => '助動詞',
                    'partOfSpeechSubtype' => '*',
                    'conjugationType' => '特殊・マス',
                    'conjugationForm' => '連用形',
                ],
            ],
            [
                [
                    'surface' => '本',
                    'base' => '本',
                    'partOfSpeech' => '名詞',
                    'partOfSpeechSubtype' => '一般',
                    'conjugationType' => '*',
                    'conjugationForm' => '*',
                ],
            ],
        ], $groups);
    }

    public function test_it_parses_homebrew_unidic_output_and_pads_missing_groups(): void
    {
        $groups = (new MecabJapaneseTokenizer)->parseOutput(<<<'OUTPUT'
読み	ヨミ	ヨム	読む	動詞-一般	五段-マ行	連用形-一般
EOS
OUTPUT, 2);

        $this->assertSame([
            [
                [
                    'surface' => '読み',
                    'base' => '読む',
                    'partOfSpeech' => '動詞-一般',
                    'partOfSpeechSubtype' => '',
                    'conjugationType' => '五段-マ行',
                    'conjugationForm' => '連用形-一般',
                ],
            ],
            [],
        ], $groups);
    }

    public function test_it_falls_back_to_empty_tokens_and_logs_an_unavailable_binary_once(): void
    {
        config()->set('services.mecab.binary', '/definitely-missing/convolab-mecab');
        Log::spy();
        $tokenizer = new MecabJapaneseTokenizer;

        $this->assertSame([[], []], $tokenizer->tokenize(['本', '読む']));
        $this->assertSame([[]], $tokenizer->tokenize(['学生']));
        $this->assertTrue($tokenizer->hadFailure());

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Japanese tokenization is unavailable; concept matching is using exact fields only.'
                && is_string($context['error'] ?? null)
                && $context['error'] !== ''
            );
    }
}
