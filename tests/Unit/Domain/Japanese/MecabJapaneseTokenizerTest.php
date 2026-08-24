<?php

namespace Tests\Unit\Domain\Japanese;

use App\Domain\Japanese\Services\MecabJapaneseTokenizer;
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
                ['surface' => '読み', 'base' => '読む'],
                ['surface' => 'ました', 'base' => 'ます'],
            ],
            [
                ['surface' => '本', 'base' => '本'],
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
                ['surface' => '読み', 'base' => '読む'],
            ],
            [],
        ], $groups);
    }
}
