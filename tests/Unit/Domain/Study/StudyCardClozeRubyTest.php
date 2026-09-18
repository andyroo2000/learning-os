<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Support\StudyCardPresentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StudyCardClozeRubyTest extends TestCase
{
    #[DataProvider('clozeReadings')]
    public function test_mask_offsets_follow_the_reading_text(array $input, ?string $expectedRuby): void
    {
        $card = new Card;
        $card->card_type = CardType::Cloze;
        $card->prompt_json = ['clozeText' => $input['cloze']];
        $card->answer_json = ['restoredText' => $input['plain'], 'restoredTextReading' => $input['ruby']];

        $presentation = StudyCardPresentation::fromCard($card);

        $this->assertSame($expectedRuby, $presentation['front']['ruby']);
        $this->assertSame($input['ruby'], $card->answer_json['restoredTextReading']);
        $this->assertSame($input['cloze'], $card->prompt_json['clozeText']);
    }

    public static function clozeReadings(): array
    {
        return [
            'reported book card' => [[
                'cloze' => 'この本はとても{{c1::役に立ちます}}。',
                'plain' => 'この本はとても役に立ちます。',
                'ruby' => 'この 本[ほん]は とても 役[やく]に 立[た]ちます。',
            ], 'この 本[ほん]は とても [...]。'],
            'spaces absent from reading' => [[
                'cloze' => '会社で {{c1::働く}} 。',
                'plain' => '会社で 働く 。',
                'ruby' => '会社[かいしゃ]で働[はたら]く。',
            ], '会社[かいしゃ]で[...]。'],
            'unicode whitespace and reading on both sides' => [[
                'cloze' => '毎日{{c1::会社}}で働く。',
                'plain' => '毎日会社で働く。',
                'ruby' => "毎日[まいにち]\u{3000}会社[かいしゃ]\tで\n働[はたら]く。",
            ], "毎日[まいにち]\u{3000}[...]\tで\n働[はたら]く。"],
            'blank at the start' => [[
                'cloze' => '{{c1::会社}}で働く。',
                'plain' => '会社で働く。',
                'ruby' => '会社[かいしゃ] で 働[はたら]く。',
            ], '[...] で 働[はたら]く。'],
            'blank at the end' => [[
                'cloze' => '会社で{{c1::働く}}',
                'plain' => '会社で働く',
                'ruby' => '会社[かいしゃ] で 働[はたら]く',
            ], '会社[かいしゃ] で [...]'],
            'partial compound excludes the hidden reading' => [[
                'cloze' => '日本{{c1::語}}を勉強する。',
                'plain' => '日本語を勉強する。',
                'ruby' => '日本語[にほんご] を 勉強[べんきょう]する。',
            ], '日本[...] を 勉強[べんきょう]する。'],
            'multiple blanks remain masked' => [[
                'cloze' => '{{c1::春}}に花が{{c1::咲く}}。',
                'plain' => '春に花が咲く。',
                'ruby' => '春[はる] に 花[はな]が 咲[さ]く。',
            ], null],
            'mismatched reading falls back to the masked plain text' => [[
                'cloze' => '会社で{{c1::働く}}。',
                'plain' => '会社で働く。',
                'ruby' => '学校[がっこう] で 働[はたら]く。',
            ], null],
        ];
    }
}
