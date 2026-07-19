<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Study\Results\DailyAudioLearningAtom;
use App\Domain\Study\Results\DailyAudioScriptUnit;
use App\Domain\Study\Services\DailyAudioDrillScriptGenerator;
use App\Domain\Study\Services\OpenAiStudyCardGenerator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class DailyAudioDrillScriptGeneratorTest extends TestCase
{
    public function test_it_builds_balanced_recognition_and_production_drills_from_safe_enhancements(): void
    {
        $response = [
            'items' => [[
                'cardId' => 'card-1',
                'englishCue' => 'prices',
                'anchor' => [
                    'japanese' => 'この町は物価が高いです。',
                    'reading' => 'この町[まち]は物価[ぶっか]が高[たか]いです。',
                    'english' => 'The cost of living is high in this town.',
                ],
                'grammarSubstitutions' => [
                    [
                        'japanese' => '東京は物価が高いです。',
                        'reading' => 'Tokyo wa bukka ga takai desu.',
                        'english' => 'Prices are high in Tokyo.',
                    ],
                    [
                        'japanese' => '大阪は物価が安いです。',
                        'reading' => '大阪[おおさか]は物価[ぶっか]が安[やす]いです。',
                        'english' => 'Prices are low in Osaka.',
                    ],
                    [
                        'japanese' => '京都は家賃が高いです。',
                        'reading' => '京都[きょうと]は家賃[やちん]が高[たか]いです。',
                        'english' => 'Rent is high in Kyoto.',
                    ],
                ],
                'formTransforms' => [
                    [
                        'japanese' => '物価が高くなりました。',
                        'reading' => '物価[ぶっか]が高[たか]くなりました。',
                        'english' => 'The cost of living became high.',
                    ],
                    [
                        'japanese' => '物価が下がりませんでした。',
                        'english' => 'The cost of living did not go down.',
                    ],
                    [
                        'japanese' => '物価は高くありません。',
                        'reading' => '物価[ぶっか]は高[たか]くありません。',
                        'english' => 'The cost of living is not high.',
                    ],
                ],
            ]],
        ];
        $generator = $this->generatorReturning($response, function (
            string $system,
            string $prompt,
            ?string $model,
            ?string $reasoningEffort,
        ): bool {
            $this->assertStringContainsString('English fields must contain English only', $system);
            $this->assertStringContainsString('balanced ladder', $prompt);
            $this->assertStringContainsString('"cardId": "card-1"', $prompt);
            $this->assertSame('gpt-daily-test', $model);
            $this->assertSame('low', $reasoningEffort);

            return true;
        });

        config()->set([
            'services.openai.daily_audio_model' => 'gpt-daily-test',
            'services.openai.daily_audio_reasoning_effort' => 'low',
        ]);

        $result = $generator->generate(
            [$this->atom(english: '物の値段')],
            'fishaudio:english',
            'ja-JP-Wavenet-C',
        );
        $script = $result->scriptUnits();
        $l2Units = collect($script)->where('type', 'L2');

        $this->assertTrue($l2Units->contains('text', 'この町は物価が高いです。'));
        $this->assertTrue($l2Units->contains('text', '大阪は物価が安いです。'));
        $this->assertTrue($l2Units->contains('text', '京都は家賃が高いです。'));
        $this->assertTrue($l2Units->contains('text', '物価が高くなりました。'));
        $this->assertTrue($l2Units->contains('text', '物価は高くありません。'));
        $this->assertFalse($l2Units->contains('text', '東京は物価が高いです。'));
        $this->assertFalse($l2Units->contains('text', '物価が下がりませんでした。'));
        $this->assertStringNotContainsString(
            'Tokyo wa',
            json_encode($script, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
        $this->assertSame([
            'enhancedAtomCount' => 1,
            'generatedPromptCount' => 5,
            'fallbackPromptCount' => 0,
            'missingCueCount' => 0,
            'totalPromptCount' => 5,
            'unitCount' => 78,
            'l2UnitCount' => 20,
            'l2UnitsWithReadingCount' => 20,
            'l2UnitsMissingReadingCount' => 0,
        ], $result->metadata);
    }

    public function test_one_failed_enhancement_batch_does_not_erase_other_batches(): void
    {
        Log::spy();
        $openAi = $this->mock(OpenAiStudyCardGenerator::class);
        $openAi->shouldReceive('generateJson')
            ->once()
            ->ordered()
            ->andReturn(json_encode([
                'items' => [[
                    'cardId' => 'card-1',
                    'englishCue' => 'to win',
                    'anchor' => [
                        'japanese' => '昨日、試合に勝ちました。',
                        'reading' => '昨日[きのう]、試合[しあい]に勝[か]ちました。',
                        'english' => 'I won the game yesterday.',
                    ],
                ]],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $openAi->shouldReceive('generateJson')
            ->once()
            ->ordered()
            ->andThrow(new RuntimeException('provider detail must stay internal'));

        $atoms = collect(range(1, 6))->map(fn (int $index): DailyAudioLearningAtom => $this->atom(
            cardId: "card-{$index}",
            targetText: $index === 1 ? '勝つ' : "ことば{$index}",
            english: $index === 1 ? 'to win' : "word {$index}",
            reading: null,
        ));

        $result = (new DailyAudioDrillScriptGenerator($openAi))->generate(
            $atoms,
            'fishaudio:english',
            'ja-JP-Wavenet-C',
        );

        $this->assertSame(1, $result->metadata['enhancedAtomCount']);
        $this->assertSame(1, $result->metadata['generatedPromptCount']);
        $this->assertSame(5, $result->metadata['fallbackPromptCount']);
        $this->assertSame(6, $result->metadata['totalPromptCount']);
        $this->assertTrue(collect($result->scriptUnits())
            ->where('type', 'L2')
            ->contains('text', '昨日、試合に勝ちました。'));
        $this->assertTrue(collect($result->scriptUnits())
            ->where('type', 'L2')
            ->contains('text', 'ことば6'));
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'deterministic fallbacks')
                    && $context === [
                        'card_count' => 1,
                        'exception' => RuntimeException::class,
                    ];
            });
    }

    public function test_it_uses_a_generated_english_cue_when_the_card_definition_is_mixed_language(): void
    {
        $generator = $this->generatorReturning([
            'items' => [[
                'cardId' => 'card-1',
                'englishCue' => 'to eat',
            ]],
        ]);

        $result = $generator->generate(
            [$this->atom(
                targetText: '食べる',
                reading: '食[た]べる',
                english: '食べるto eat',
            )],
            'fishaudio:english',
            'ja-JP-Wavenet-C',
        );
        $narration = collect($result->scriptUnits())
            ->where('type', 'narration_L1')
            ->pluck('text')
            ->implode(' ');

        $this->assertStringContainsString('How do you say "to eat"?', $narration);
        $this->assertDoesNotMatchRegularExpression(
            '/[\x{3040}-\x{30ff}\x{3400}-\x{9fff}]/u',
            $narration,
        );
        $this->assertSame(1, $result->metadata['fallbackPromptCount']);
        $this->assertSame(0, $result->metadata['missingCueCount']);
    }

    public function test_variations_only_enhancement_keeps_a_literal_target_prompt(): void
    {
        $generator = $this->generatorReturning([
            'items' => [[
                'cardId' => 'card-1',
                'englishCue' => 'prices',
                'anchor' => [
                    'japanese' => 'この町は物価が高いです。',
                    'english' => 'The cost of living is high in this town.',
                ],
                'grammarSubstitutions' => [[
                    'japanese' => '大阪は物価が安いです。',
                    'reading' => '大阪[おおさか]は物価[ぶっか]が安[やす]いです。',
                    'english' => 'The cost of living is low in Osaka.',
                ]],
            ]],
        ]);

        $result = $generator->generate(
            [$this->atom()],
            'fishaudio:english',
            'ja-JP-Wavenet-C',
        );
        $l2Text = collect($result->scriptUnits())->where('type', 'L2')->pluck('text');

        $this->assertTrue($l2Text->contains('物価'));
        $this->assertTrue($l2Text->contains('大阪は物価が安いです。'));
        $this->assertFalse($l2Text->contains('この町は物価が高いです。'));
        $this->assertSame(1, $result->metadata['enhancedAtomCount']);
        $this->assertSame(1, $result->metadata['generatedPromptCount']);
        $this->assertSame(1, $result->metadata['fallbackPromptCount']);
        $this->assertSame(2, $result->metadata['totalPromptCount']);
    }

    public function test_it_normalizes_inline_furigana_and_deduplicates_repeated_prompts(): void
    {
        $generator = $this->generatorReturning([
            'items' => [
                [
                    'cardId' => 'card-1',
                    'englishCue' => 'went to Hokkaido',
                    'anchor' => [
                        'japanese' => '北海道[ほっかいどう]に行(い)きました。',
                        'english' => 'I went to Hokkaido.',
                    ],
                ],
                [
                    'cardId' => 'card-2',
                    'englishCue' => 'went to Hokkaido',
                    'anchor' => [
                        'japanese' => '北海道[ほっかいどう]に行(い)きました。',
                        'english' => 'I went to Hokkaido.',
                    ],
                ],
            ],
        ]);

        $result = $generator->generate(
            [
                $this->atom(cardId: 'card-1'),
                $this->atom(cardId: 'card-2'),
            ],
            'fishaudio:english',
            'ja-JP-Wavenet-C',
        );
        $l2Units = collect($result->scriptUnits())->where('type', 'L2');

        $this->assertCount(4, $l2Units);
        $this->assertSame(
            ['北海道に行きました。'],
            $l2Units->pluck('text')->unique()->values()->all(),
        );
        $this->assertSame(
            ['北海道[ほっかいどう]に行[い]きました。'],
            $l2Units->pluck('reading')->unique()->values()->all(),
        );
        $this->assertSame(1, $result->metadata['totalPromptCount']);
    }

    public function test_it_bounds_atoms_and_enhances_them_in_five_card_batches(): void
    {
        $openAi = $this->mock(OpenAiStudyCardGenerator::class);
        $openAi->shouldReceive('generateJson')
            ->times(10)
            ->andReturn(json_encode(['items' => []], JSON_THROW_ON_ERROR));
        $atoms = collect(range(1, 55))->map(fn (int $index): DailyAudioLearningAtom => $this->atom(
            cardId: "card-{$index}",
            targetText: "ことば{$index}",
            english: "word {$index}",
            reading: null,
        ));

        $result = (new DailyAudioDrillScriptGenerator($openAi))->generate(
            $atoms,
            'fishaudio:english',
            'ja-JP-Wavenet-C',
        );
        $l2Text = collect($result->scriptUnits())->where('type', 'L2')->pluck('text');

        $this->assertSame(50, $result->metadata['totalPromptCount']);
        $this->assertTrue($l2Text->contains('ことば50'));
        $this->assertFalse($l2Text->contains('ことば51'));
    }

    public function test_it_reports_missing_cues_without_sending_mixed_language_to_the_narrator(): void
    {
        $generator = $this->generatorReturning(['items' => []]);

        $result = $generator->generate(
            [$this->atom(english: '食べるto eat', exampleEn: null)],
            'fishaudio:english',
            'ja-JP-Wavenet-C',
        );

        $this->assertSame(1, $result->metadata['missingCueCount']);
        $this->assertSame(0, $result->metadata['totalPromptCount']);
        $this->assertSame(0, $result->metadata['l2UnitCount']);
    }

    public function test_it_requires_atoms_and_both_voice_roles(): void
    {
        $openAi = $this->mock(OpenAiStudyCardGenerator::class);
        $openAi->shouldNotReceive('generateJson');
        $generator = new DailyAudioDrillScriptGenerator($openAi);

        try {
            $generator->generate([], 'narrator', 'speaker');
            $this->fail('Expected empty atoms to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Daily Audio Practice needs at least one eligible study card.',
                $exception->getMessage(),
            );
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires narrator and speaker voice IDs');
        $generator->generate([$this->atom()], 'narrator', '');
    }

    public function test_script_units_reject_invalid_shapes_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive duration');

        DailyAudioScriptUnit::pause(0);
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  null|callable(string, string, ?string, ?string): bool  $assertRequest
     */
    private function generatorReturning(
        array $response,
        ?callable $assertRequest = null,
    ): DailyAudioDrillScriptGenerator {
        $openAi = $this->mock(OpenAiStudyCardGenerator::class);
        $expectation = $openAi->shouldReceive('generateJson')->once();
        if ($assertRequest !== null) {
            $expectation->withArgs($assertRequest);
        }
        $expectation->andReturn(json_encode(
            $response,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));

        return new DailyAudioDrillScriptGenerator($openAi);
    }

    private function atom(
        string $cardId = 'card-1',
        string $targetText = '物価',
        ?string $reading = '物価[ぶっか]',
        string $english = 'prices',
        ?string $exampleJp = null,
        ?string $exampleEn = null,
    ): DailyAudioLearningAtom {
        return new DailyAudioLearningAtom(
            cardId: $cardId,
            cardType: 'recognition',
            targetText: $targetText,
            reading: $reading,
            english: $english,
            exampleJp: $exampleJp,
            exampleEn: $exampleEn,
            deckName: 'Japanese',
            noteType: 'Core',
        );
    }
}
