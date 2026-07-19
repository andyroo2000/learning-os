<?php

namespace Tests\Unit\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Actions\BuildDailyAudioLearningAtomsAction;
use PHPUnit\Framework\TestCase;

class BuildDailyAudioLearningAtomsActionTest extends TestCase
{
    public function test_it_builds_the_convolab_compatibility_atom_shape(): void
    {
        $card = $this->card([
            'convolab_id' => '33cb3d35-8566-4dd5-aebe-af1725c3d18a',
            'card_type' => CardType::Production,
            'prompt_json' => [
                'cueText' => '食べる',
                'cueReading' => 'たべる',
            ],
            'answer_json' => [
                'meaning' => 'to eat',
                'sentenceJp' => '毎朝パンを食べる。',
                'sentenceEn' => 'I eat bread every morning.',
            ],
            'source_deck_name' => 'Core Japanese',
            'source_notetype_name' => 'Vocabulary',
        ]);

        $atom = app(BuildDailyAudioLearningAtomsAction::class)->handle([$card])->sole();

        $this->assertSame([
            'cardId' => '33cb3d35-8566-4dd5-aebe-af1725c3d18a',
            'cardType' => 'production',
            'targetText' => '食べる',
            'reading' => 'たべる',
            'english' => 'to eat',
            'exampleJp' => '毎朝パンを食べる。',
            'exampleEn' => 'I eat bread every morning.',
            'deckName' => 'Core Japanese',
            'noteType' => 'Vocabulary',
        ], $atom->toArray());
    }

    public function test_it_preserves_field_precedence_and_normalizes_html(): void
    {
        $card = $this->card([
            'prompt_json' => [
                'clozeAnswerText' => '<div>第一候補</div>',
                'cueText' => 'second choice',
                'cueReading' => '<span>だいいちこうほ</span>',
            ],
            'answer_json' => [
                'expression' => 'third choice',
                'meaning' => '<p>first &amp; best</p>',
                'sentenceJp' => '<div>例文です。</div>',
                'sentenceEn' => '<p>An example.</p>',
            ],
            'convolab_note_raw_fields_json' => [
                'Expression' => 'raw fallback',
                'Meaning' => 'raw meaning',
            ],
        ]);

        $atom = app(BuildDailyAudioLearningAtomsAction::class)->handle([$card])->sole();

        $this->assertSame('第一候補', $atom->targetText);
        $this->assertSame('だいいちこうほ', $atom->reading);
        $this->assertSame('first & best', $atom->english);
        $this->assertSame('例文です。', $atom->exampleJp);
        $this->assertSame('An example.', $atom->exampleEn);
    }

    public function test_it_extracts_an_english_segment_from_a_mixed_field(): void
    {
        $card = $this->card([
            'prompt_json' => ['cueText' => '猫'],
            'answer_json' => [
                'meaning' => "猫。 cat\nねこ",
                'sentenceEn' => 'feline animal',
            ],
        ]);

        $atom = app(BuildDailyAudioLearningAtomsAction::class)->handle([$card])->sole();

        $this->assertSame('cat', $atom->english);
    }

    public function test_it_falls_back_through_copied_note_fields(): void
    {
        $card = $this->card([
            'prompt_json' => [],
            'answer_json' => [],
            'convolab_note_raw_fields_json' => [
                'Expression' => '<b>勉強する</b>',
                'Reading' => 'べんきょうする',
                'Translation' => 'to study',
            ],
            'source_deck_name' => '  Imported Deck  ',
            'source_notetype_name' => '  Basic  ',
        ]);

        $atom = app(BuildDailyAudioLearningAtomsAction::class)->handle([$card])->sole();

        $this->assertSame('勉強する', $atom->targetText);
        $this->assertSame('べんきょうする', $atom->reading);
        $this->assertSame('to study', $atom->english);
        $this->assertSame('Imported Deck', $atom->deckName);
        $this->assertSame('Basic', $atom->noteType);
    }

    public function test_it_uses_target_text_when_no_meaning_exists(): void
    {
        $card = $this->card([
            'prompt_json' => ['cueText' => 'こんにちは'],
            'answer_json' => [],
        ]);

        $atom = app(BuildDailyAudioLearningAtomsAction::class)->handle([$card])->sole();

        $this->assertSame('こんにちは', $atom->english);
    }

    public function test_it_omits_cards_without_target_text(): void
    {
        $missing = $this->card([
            'prompt_json' => [],
            'answer_json' => ['meaning' => 'meaning only'],
        ]);
        $present = $this->card([
            'prompt_json' => ['cueText' => 'ある'],
            'answer_json' => ['meaning' => 'to exist'],
        ]);

        $atoms = app(BuildDailyAudioLearningAtomsAction::class)->handle([$missing, $present]);

        $this->assertCount(1, $atoms);
        $this->assertSame($present->id, $atoms->sole()->cardId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(array $attributes): Card
    {
        $card = new Card;
        $card->id = $attributes['id'] ?? '01K0A0B0C0D0E0F0G0H0J0K0M0';
        $card->forceFill([
            'convolab_id' => null,
            'card_type' => CardType::Recognition,
            'prompt_json' => [],
            'answer_json' => [],
            'convolab_note_raw_fields_json' => null,
            'source_deck_name' => null,
            'source_notetype_name' => null,
            ...$attributes,
        ]);

        return $card;
    }
}
