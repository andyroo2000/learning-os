<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Study\Results\DailyAudioLearningAtom;
use App\Domain\Study\Services\DailyAudioContextTrackGenerator;
use App\Domain\Study\Services\OpenAiStudyCardGenerator;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class DailyAudioContextTrackGeneratorTest extends TestCase
{
    public function test_it_builds_dialogue_scenes_with_alternating_voices(): void
    {
        $this->mock(OpenAiStudyCardGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateJson')
                ->once()
                ->withArgs(function (string $systemInstruction): bool {
                    $this->assertStringContainsString(
                        'optional inspiration, not a checklist',
                        $systemInstruction,
                    );
                    $this->assertStringContainsString(
                        'Prioritize believable conversational flow',
                        $systemInstruction,
                    );

                    return true;
                })
                ->andReturn(json_encode([
                    'scenes' => [[
                        'title' => 'At the station',
                        'lines' => [
                            [
                                'speaker' => 'speaker1',
                                'text' => 'こんにちは。',
                                'reading' => 'こんにちは。',
                                'translation' => 'Hello there.',
                            ],
                            [
                                'speaker' => 'speaker2',
                                'text' => 'えきはどこですか。',
                                'reading' => 'えきはどこですか。',
                                'translation' => 'Where is the station?',
                            ],
                        ],
                    ]],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        });

        $result = app(DailyAudioContextTrackGenerator::class)->generateDialogue(
            [$this->atom()],
            'narrator',
            'speaker-one',
            'speaker-two',
        );
        $spoken = $result->units->where('type', 'L2')->values();

        $this->assertCount(2, $spoken);
        $this->assertSame('speaker-one', $spoken[0]->voiceId);
        $this->assertSame('speaker-two', $spoken[1]->voiceId);
        $this->assertSame('At the station', $result->units->where('type', 'marker')->last()->label);
        $this->assertTrue($result->metadata['providerGenerated']);
        $this->assertSame(1, $result->metadata['sceneCount']);
        $this->assertSame(2, $result->metadata['generatedLineCount']);
    }

    public function test_it_builds_a_coherent_story_track(): void
    {
        $this->mock(OpenAiStudyCardGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateJson')
                ->once()
                ->withArgs(function (string $systemInstruction): bool {
                    $this->assertStringContainsString(
                        'Story quality comes first',
                        $systemInstruction,
                    );
                    $this->assertStringContainsString(
                        'Do not insert an item merely to achieve coverage',
                        $systemInstruction,
                    );

                    return true;
                })
                ->andReturn(json_encode([
                    'title' => 'A Small Cat',
                    'lines' => [[
                        'text' => 'ねこがいます。',
                        'reading' => 'ねこがいます。',
                        'translation' => 'There is a cat.',
                    ]],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        });

        $result = app(DailyAudioContextTrackGenerator::class)->generateStory(
            [$this->atom()],
            'narrator',
            'speaker',
        );

        $this->assertSame(
            'Finally, a short story: A Small Cat.',
            $result->units->firstWhere('type', 'narration_L1')->text,
        );
        $this->assertSame('ねこがいます。', $result->units->firstWhere('type', 'L2')->text);
        $this->assertTrue($result->metadata['providerGenerated']);
        $this->assertTrue($result->metadata['hasTitle']);
    }

    public function test_provider_failures_fall_back_to_the_selected_cards(): void
    {
        $this->mock(OpenAiStudyCardGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateJson')
                ->twice()
                ->andThrow(new RuntimeException('Provider unavailable.'));
        });
        $generator = app(DailyAudioContextTrackGenerator::class);

        $dialogue = $generator->generateDialogue(
            [$this->atom()],
            'narrator',
            'speaker-one',
            'speaker-two',
        );
        $story = $generator->generateStory(
            [$this->atom()],
            'narrator',
            'speaker-one',
        );

        $this->assertFalse($dialogue->metadata['providerGenerated']);
        $this->assertFalse($story->metadata['providerGenerated']);
        $this->assertSame('猫', $dialogue->units->firstWhere('type', 'L2')->text);
        $this->assertSame('猫', $story->units->firstWhere('type', 'L2')->text);
    }

    private function atom(): DailyAudioLearningAtom
    {
        return new DailyAudioLearningAtom(
            cardId: 'card-1',
            cardType: 'recognition',
            targetText: '猫',
            reading: 'ねこ',
            english: 'cat',
            exampleJp: null,
            exampleEn: null,
            deckName: null,
            noteType: null,
        );
    }
}
