<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Services\OpenAiStudyCardGenerator;
use App\Domain\Study\Services\StudyCardDraftEnricher;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudyCardDraftRecognitionHintTest extends TestCase
{
    #[DataProvider('recognitionPrompts')]
    public function test_it_does_not_generate_english_hints_for_recognition(
        StudyCardCreationKind $kind,
        array $prompt,
    ): void {
        $draft = $this->draft($kind, $prompt);
        $this->mockGeneratedPrompt(['cueMeaning' => 'It has been getting colder recently.']);

        $result = app(StudyCardDraftEnricher::class)->enrich($draft);

        $this->assertSame($prompt, $result['prompt']);
        $this->assertSame('最近、寒くなってきました。', $result['answer']['expression']);
        $this->assertSame('It has been getting colder recently.', $result['answer']['meaning']);
    }

    public static function recognitionPrompts(): iterable
    {
        foreach ([StudyCardCreationKind::TextRecognition, StudyCardCreationKind::AudioRecognition] as $kind) {
            $prompt = $kind === StudyCardCreationKind::TextRecognition
                ? ['cueText' => '最近、寒くなってきました。'] : [];

            yield $kind->value.' omitted' => [$kind, $prompt];
            yield $kind->value.' null' => [$kind, [...$prompt, 'cueMeaning' => null]];
            yield $kind->value.' empty' => [$kind, [...$prompt, 'cueMeaning' => '']];
            yield $kind->value.' whitespace' => [$kind, [...$prompt, 'cueMeaning' => '  ']];
        }
    }

    public function test_it_preserves_a_deliberately_supplied_manual_hint(): void
    {
        $draft = $this->draft(StudyCardCreationKind::TextRecognition, [
            'cueText' => '最近、寒くなってきました。',
            'cueMeaning' => 'Change leading up to now',
        ]);
        $this->mockGeneratedPrompt(['cueMeaning' => 'It has been getting colder recently.']);

        $result = app(StudyCardDraftEnricher::class)->enrich($draft);

        $this->assertSame('Change leading up to now', $result['prompt']['cueMeaning']);
    }

    public function test_it_still_generates_cloze_hints(): void
    {
        $draft = $this->draft(StudyCardCreationKind::Cloze, [
            'clozeText' => '最近、{{c1::寒くなってきました}}。',
        ]);
        $this->mockGeneratedPrompt(['clozeHint' => 'has been getting colder; polite past']);

        $result = app(StudyCardDraftEnricher::class)->enrich($draft);

        $this->assertSame('has been getting colder; polite past', $result['prompt']['clozeHint']);
        $this->assertSame('最近、寒くなってきました。', $result['answer']['restoredText']);
    }

    public function test_it_preserves_supplied_cloze_hints(): void
    {
        $draft = $this->draft(StudyCardCreationKind::Cloze, [
            'clozeText' => '最近、{{c1::寒くなってきました}}。',
            'clozeHint' => 'has been getting colder—change leading up to now; polite past',
        ]);
        $this->mockGeneratedPrompt(['clozeHint' => 'a different hint']);

        $result = app(StudyCardDraftEnricher::class)->enrich($draft);

        $this->assertSame($draft->prompt_json['clozeHint'], $result['prompt']['clozeHint']);
    }

    public function test_it_still_generates_production_cues(): void
    {
        $draft = $this->draft(StudyCardCreationKind::ProductionText, []);
        $this->mockGeneratedPrompt([
            'cueText' => 'It has been getting colder recently.',
            'cueMeaning' => 'Use the polite past form.',
        ]);

        $result = app(StudyCardDraftEnricher::class)->enrich($draft);

        $this->assertSame('It has been getting colder recently.', $result['prompt']['cueText']);
        $this->assertSame('Use the polite past form.', $result['prompt']['cueMeaning']);
    }

    private function draft(StudyCardCreationKind $kind, array $prompt): StudyCardDraft
    {
        return new StudyCardDraft([
            'creation_kind' => $kind,
            'image_placement' => StudyCardImagePlacement::None,
            'prompt_json' => $prompt,
            'answer_json' => [
                'expression' => '最近、寒くなってきました。',
                'restoredText' => '最近、寒くなってきました。',
                'meaning' => 'It has been getting colder recently.',
            ],
        ]);
    }

    private function mockGeneratedPrompt(array $prompt): void
    {
        $this->mock(OpenAiStudyCardGenerator::class, function (MockInterface $mock) use ($prompt): void {
            $mock->shouldReceive('generateJson')->once()->andReturn(json_encode([
                'prompt' => $prompt,
                'answer' => [],
                'imagePrompt' => null,
            ], JSON_THROW_ON_ERROR));
        });
    }
}
