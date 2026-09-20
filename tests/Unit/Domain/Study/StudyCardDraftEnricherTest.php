<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Services\OpenAiStudyCardGenerator;
use App\Domain\Study\Services\StudyCardDraftEnricher;
use App\Domain\Study\Support\StudyCardGenerationDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StudyCardDraftEnricherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_preserves_supplied_content_and_fills_missing_fields(): void
    {
        $draft = StudyCardDraft::factory()->create([
            'creation_kind' => StudyCardCreationKind::ProductionImage,
            'prompt_json' => ['cueText' => 'the company where I work'],
            'answer_json' => ['meaning' => 'company'],
            'image_placement' => StudyCardImagePlacement::Prompt,
            'image_prompt' => null,
        ]);
        $this->mockResponse([
            'prompt' => ['cueText' => 'a different cue', 'cueMeaning' => 'workplace'],
            'answer' => [
                'expression' => '会社',
                'expressionReading' => '会社[かいしゃ]',
                'meaning' => 'corporation',
                'sentenceJp' => '会社で働いています。',
                'sentenceEn' => 'I work at a company.',
            ],
            'imagePrompt' => 'A commuter arriving at a modern office building at dawn.',
        ]);

        $result = app(StudyCardDraftEnricher::class)->enrich($draft);

        $this->assertSame('the company where I work', $result['prompt']['cueText']);
        $this->assertSame('workplace', $result['prompt']['cueMeaning']);
        $this->assertSame('company', $result['answer']['meaning']);
        $this->assertSame('会社', $result['answer']['expression']);
        $this->assertSame(
            StudyCardGenerationDefaults::VOICE_ID,
            $result['answer']['answerAudioVoiceId'],
        );
        $this->assertSame(
            'A commuter arriving at a modern office building at dawn.',
            $result['imagePrompt'],
        );
    }

    public function test_it_rejects_provider_output_that_does_not_complete_the_card(): void
    {
        $draft = StudyCardDraft::factory()->create([
            'prompt_json' => [],
            'answer_json' => [],
        ]);
        $this->mockResponse([
            'prompt' => [],
            'answer' => [],
            'imagePrompt' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generated study card draft is missing required learning content.');

        app(StudyCardDraftEnricher::class)->enrich($draft);
    }

    #[DataProvider('completeCards')]
    public function test_it_accepts_required_content_for_each_card_kind(
        StudyCardCreationKind $kind,
        array $response,
    ): void {
        $this->mockResponse($response);

        $result = app(StudyCardDraftEnricher::class)->enrich($this->emptyDraft($kind));

        $this->assertSame($response['prompt'], $result['prompt']);
        $this->assertSame($response['answer']['meaning'], $result['answer']['meaning']);
    }

    #[DataProvider('incompleteCards')]
    public function test_it_rejects_each_missing_required_content_field(
        StudyCardCreationKind $kind,
        array $response,
    ): void {
        $this->mockResponse($response);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generated study card draft is missing required learning content.');

        app(StudyCardDraftEnricher::class)->enrich($this->emptyDraft($kind));
    }

    public static function completeCards(): iterable
    {
        $answer = ['expression' => '会社', 'meaning' => 'company'];

        yield 'text recognition' => [StudyCardCreationKind::TextRecognition, [
            'prompt' => ['cueText' => '会社'], 'answer' => $answer,
        ]];
        yield 'audio recognition' => [StudyCardCreationKind::AudioRecognition, [
            'prompt' => [], 'answer' => $answer,
        ]];
        yield 'text production' => [StudyCardCreationKind::ProductionText, [
            'prompt' => ['cueText' => 'company'], 'answer' => $answer,
        ]];
        yield 'image production' => [StudyCardCreationKind::ProductionImage, [
            'prompt' => ['cueText' => 'company'], 'answer' => $answer,
        ]];
        yield 'cloze' => [StudyCardCreationKind::Cloze, [
            'prompt' => ['clozeText' => '{{c1::会社}}で働いています。'],
            'answer' => ['restoredText' => '会社で働いています。', 'meaning' => 'I work at a company.'],
        ]];
    }

    public static function incompleteCards(): iterable
    {
        foreach (self::completeCards() as $name => [$kind, $response]) {
            foreach ($response as $side => $fields) {
                foreach (array_keys($fields) as $field) {
                    $incomplete = $response;
                    unset($incomplete[$side][$field]);
                    yield "$name missing $side.$field" => [$kind, $incomplete];
                }
            }
        }
    }

    #[DataProvider('imagePlacements')]
    public function test_it_requires_an_image_prompt_for_each_image_placement(StudyCardImagePlacement $placement): void
    {
        $draft = $this->emptyDraft(StudyCardCreationKind::AudioRecognition);
        $draft->image_placement = $placement;
        $this->mockResponse(['answer' => ['expression' => '会社', 'meaning' => 'company'], 'imagePrompt' => '  ']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generated study card draft is missing its image prompt.');

        app(StudyCardDraftEnricher::class)->enrich($draft);
    }

    public static function imagePlacements(): iterable
    {
        yield 'prompt' => [StudyCardImagePlacement::Prompt];
        yield 'answer' => [StudyCardImagePlacement::Answer];
        yield 'both' => [StudyCardImagePlacement::Both];
    }

    private function emptyDraft(StudyCardCreationKind $kind): StudyCardDraft
    {
        return new StudyCardDraft([
            'creation_kind' => $kind,
            'image_placement' => StudyCardImagePlacement::None,
            'prompt_json' => [],
            'answer_json' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function mockResponse(array $response): void
    {
        $this->mock(OpenAiStudyCardGenerator::class, function (MockInterface $mock) use ($response): void {
            $mock->shouldReceive('generateJson')
                ->once()
                ->andReturn(json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        });
    }
}
