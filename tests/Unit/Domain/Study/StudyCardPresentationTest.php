<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Support\StudyCardPresentation;
use PHPUnit\Framework\TestCase;

class StudyCardPresentationTest extends TestCase
{
    public function test_it_normalizes_legacy_text_payloads_without_changing_the_raw_fields(): void
    {
        $card = $this->card([
            'front_text' => 'fallback front',
            'back_text' => 'fallback back',
            'prompt_json' => ['type' => 'text', 'text' => '聞く'],
            'answer_json' => ['type' => 'text', 'text' => 'to listen'],
        ]);

        $this->assertSame([
            'version' => 1,
            'front' => [
                'mode' => 'text',
                'text' => '聞く',
                'ruby' => null,
                'hint' => null,
                'media' => ['audio' => null, 'image' => null],
                'autoplayAudio' => false,
            ],
            'answer' => [
                'heading' => 'to listen',
                'ruby' => null,
                'restored' => null,
                'meaning' => null,
                'sentences' => [
                    'japanese' => ['text' => null, 'ruby' => null],
                    'english' => ['text' => null, 'ruby' => null],
                ],
                'notes' => [],
                'media' => ['image' => null],
                'audio' => null,
                'pitchAccent' => null,
            ],
        ], StudyCardPresentation::fromCard($card));

        $this->assertSame(['type' => 'text', 'text' => '聞く'], $card->prompt_json);
        $this->assertSame(['type' => 'text', 'text' => 'to listen'], $card->answer_json);
    }

    public function test_it_builds_a_typed_rich_text_answer_projection(): void
    {
        $image = [
            'id' => 'image-1',
            'filename' => 'company.png',
            'url' => '/api/study/media/image-1',
            'mediaKind' => 'image',
            'source' => 'imported_image',
        ];
        $audio = [
            'id' => 'audio-1',
            'filename' => 'company.mp3',
            'url' => '/api/study/media/audio-1',
            'mediaKind' => 'audio',
            'source' => 'generated',
        ];
        $card = $this->card([
            'card_type' => CardType::Production,
            'prompt_json' => [
                'cueText' => 'company',
                'cueMeaning' => 'noun',
                'cueImage' => $image,
            ],
            'answer_json' => [
                'expression' => '会社',
                'expressionReading' => '会社[かいしゃ]',
                'restoredText' => '<b>会社で働く</b>',
                'meaning' => 'company &amp; workplace',
                'sentenceJp' => 'この会社で働く。',
                'sentenceJpKana' => 'この会社[かいしゃ]で働[はたら]く。',
                'sentenceEn' => 'I work at this company.',
                'notes' => '<div>• Business context</div><div>- Common word</div>',
                'answerAudio' => $audio,
                'pitchAccent' => [
                    'status' => 'resolved',
                    'expression' => '会社',
                    'reading' => 'かいしゃ',
                    'pitchNum' => 0,
                    'morae' => ['か', 'い', 'しゃ'],
                    'pattern' => [0, 1, 1],
                    'patternName' => '平板',
                    'source' => 'kanjium',
                    'resolvedBy' => 'local-reading',
                    'ignoredFutureField' => true,
                ],
            ],
        ]);

        $presentation = StudyCardPresentation::fromCard($card);

        $this->assertSame([
            'mode' => 'text',
            'text' => 'company',
            'ruby' => null,
            'hint' => 'noun',
            'media' => ['audio' => null, 'image' => $image],
            'autoplayAudio' => false,
        ], $presentation['front']);
        $this->assertSame('会社', $presentation['answer']['heading']);
        $this->assertSame('会社[かいしゃ]', $presentation['answer']['ruby']);
        $this->assertSame('会社で働く', $presentation['answer']['restored']);
        $this->assertSame('company & workplace', $presentation['answer']['meaning']);
        $this->assertSame([
            'japanese' => [
                'text' => 'この会社で働く。',
                'ruby' => 'この会社[かいしゃ]で働[はたら]く。',
            ],
            'english' => ['text' => 'I work at this company.', 'ruby' => null],
        ], $presentation['answer']['sentences']);
        $this->assertSame(['Business context', 'Common word'], $presentation['answer']['notes']);
        $this->assertSame(['image' => $image], $presentation['answer']['media']);
        $this->assertSame($audio, $presentation['answer']['audio']);
        $this->assertSame([
            'status' => 'resolved',
            'expression' => '会社',
            'reading' => 'かいしゃ',
            'pitchNum' => 0,
            'morae' => ['か', 'い', 'しゃ'],
            'pattern' => [0, 1, 1],
            'patternName' => '平板',
            'source' => 'kanjium',
            'resolvedBy' => 'local-reading',
        ], $presentation['answer']['pitchAccent']);
    }

    public function test_it_projects_audio_led_cards_with_the_logical_audio_fallback(): void
    {
        $audio = [
            'id' => 'audio-1',
            'filename' => 'listen.mp3',
            'url' => '/api/study/media/audio-1',
            'mediaKind' => 'audio',
            'source' => 'generated',
        ];
        $answerAudio = [
            'id' => 'legacy-answer-audio',
            'filename' => 'legacy.mp3',
            'url' => '/api/study/media/legacy-answer-audio',
            'mediaKind' => 'audio',
            'source' => 'imported',
        ];
        $card = $this->card([
            'front_text' => 'The legacy search text must not become a visual prompt.',
            'prompt_json' => ['cueAudio' => $audio],
            'answer_json' => [
                'expression' => '聞く',
                'meaning' => 'to listen',
                'answerAudio' => $answerAudio,
            ],
        ]);

        $presentation = StudyCardPresentation::fromCard($card);

        $this->assertSame('media', $presentation['front']['mode']);
        $this->assertNull($presentation['front']['text']);
        $this->assertSame($audio, $presentation['front']['media']['audio']);
        $this->assertTrue($presentation['front']['autoplayAudio']);
        $this->assertSame($audio, $presentation['answer']['audio']);

        $malformedLegacyPrompt = $this->card([
            'prompt_json' => ['cueAudio' => ['not-an-object']],
            'answer_json' => ['answerAudio' => $answerAudio],
        ]);
        $this->assertSame(
            $answerAudio,
            StudyCardPresentation::fromCard($malformedLegacyPrompt)['answer']['audio'],
        );
    }

    public function test_it_uses_reading_only_payloads_as_the_answer_heading(): void
    {
        $card = $this->card([
            'prompt_json' => ['cueText' => 'company'],
            'answer_json' => ['expressionReading' => '会社[かいしゃ]'],
        ]);

        $answer = StudyCardPresentation::fromCard($card)['answer'];

        $this->assertSame('会社', $answer['heading']);
        $this->assertSame('会社[かいしゃ]', $answer['ruby']);
    }

    public function test_media_led_production_cards_only_show_recognized_visual_cue_labels(): void
    {
        $image = [
            'filename' => 'company.png',
            'url' => '/api/study/media/image-1',
            'mediaKind' => 'image',
            'source' => 'generated',
        ];
        $recognized = $this->card([
            'card_type' => CardType::Production,
            'prompt_json' => ['cueImage' => $image, 'cueMeaning' => '名詞'],
        ]);
        $unrecognized = $this->card([
            'card_type' => CardType::Production,
            'prompt_json' => ['cueImage' => $image, 'cueMeaning' => 'company'],
        ]);

        $recognizedFront = StudyCardPresentation::fromCard($recognized)['front'];
        $unrecognizedFront = StudyCardPresentation::fromCard($unrecognized)['front'];

        $this->assertSame('media', $recognizedFront['mode']);
        $this->assertSame('名詞', $recognizedFront['hint']);
        $this->assertSame('media', $unrecognizedFront['mode']);
        $this->assertNull($unrecognizedFront['hint']);
    }

    public function test_it_derives_safe_cloze_faces_and_masks_the_ruby_heading(): void
    {
        $image = [
            'filename' => 'bath.png',
            'url' => '/api/study/media/image-1',
            'mediaKind' => 'image',
            'source' => 'imported_image',
        ];
        $card = $this->card([
            'card_type' => CardType::Cloze,
            'prompt_json' => [
                'clozeText' => 'お風呂に{{c1::虫::creature}}がいる！',
                'clozeHint' => 'fallback hint',
                'cueImage' => $image,
            ],
            'answer_json' => [
                'restoredText' => 'お風呂に虫がいる！',
                'restoredTextReading' => 'お風呂[ふろ]に虫[むし]がいる！',
                'meaning' => 'There is a bug in the bath!',
                'notes' => "• Casual\n- Surprise",
            ],
        ]);

        $presentation = StudyCardPresentation::fromCard($card);

        $this->assertSame([
            'mode' => 'cloze',
            'text' => 'お風呂に[...]がいる！',
            'ruby' => 'お風呂[ふろ]に[...]がいる！',
            'hint' => 'creature',
            'media' => ['audio' => null, 'image' => $image],
            'autoplayAudio' => false,
        ], $presentation['front']);
        $this->assertSame('お風呂に虫がいる！', $presentation['answer']['heading']);
        $this->assertSame('お風呂[ふろ]に虫[むし]がいる！', $presentation['answer']['ruby']);
        $this->assertSame('お風呂に虫がいる！', $presentation['answer']['restored']);
        $this->assertSame(['Casual', 'Surprise'], $presentation['answer']['notes']);
        $this->assertSame(['image' => $image], $presentation['answer']['media']);
    }

    public function test_it_normalizes_loose_cloze_brackets_without_treating_furigana_as_a_blank(): void
    {
        $card = $this->card([
            'card_type' => CardType::Cloze,
            'prompt_json' => ['clozeText' => '会社[かいしゃ]で[働く]'],
            'answer_json' => [],
        ]);

        $presentation = StudyCardPresentation::fromCard($card);

        $this->assertSame('会社で[...]', $presentation['front']['text']);
        $this->assertSame('会社[かいしゃ]で[...]', $presentation['front']['ruby']);
        $this->assertSame('会社で働く', $presentation['answer']['heading']);
        $this->assertSame('会社[かいしゃ]で働く', $presentation['answer']['ruby']);
        $this->assertSame('会社で働く', $presentation['answer']['restored']);
    }

    public function test_it_uses_an_explicit_legacy_cloze_display_without_revealing_resolved_text(): void
    {
        $card = $this->card([
            'card_type' => CardType::Cloze,
            'prompt_json' => ['clozeDisplayText' => 'The [...] is hidden.'],
            'answer_json' => ['restoredText' => 'The answer is hidden.'],
        ]);

        $presentation = StudyCardPresentation::fromCard($card);

        $this->assertSame('The [...] is hidden.', $presentation['front']['text']);
        $this->assertSame('The answer is hidden.', $presentation['answer']['restored']);
    }

    public function test_it_keeps_unresolved_or_malformed_pitch_accent_out_of_the_rendering_contract(): void
    {
        $unresolved = $this->card([
            'answer_json' => ['pitchAccent' => ['status' => 'unresolved', 'reason' => 'not-found']],
        ]);
        $malformed = $this->card([
            'answer_json' => [
                'pitchAccent' => [
                    'status' => 'resolved',
                    'expression' => '会社',
                    'reading' => 'かいしゃ',
                    'morae' => ['か', 'い', 'しゃ'],
                    'pattern' => [0, 1],
                    'patternName' => '平板',
                ],
            ],
        ]);

        $this->assertNull(StudyCardPresentation::fromCard($unresolved)['answer']['pitchAccent']);
        $this->assertNull(StudyCardPresentation::fromCard($malformed)['answer']['pitchAccent']);
        $this->assertSame(
            ['status' => 'unresolved', 'reason' => 'not-found'],
            $unresolved->answer_json['pitchAccent'],
        );
    }

    /** @param array<string, mixed> $attributes */
    private function card(array $attributes): Card
    {
        $card = new Card;
        $card->forceFill([
            'front_text' => 'front',
            'back_text' => 'back',
            'card_type' => CardType::Recognition,
            'prompt_json' => [],
            'answer_json' => [],
            ...$attributes,
        ]);

        return $card;
    }
}
