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
