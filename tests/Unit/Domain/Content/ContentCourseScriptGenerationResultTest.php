<?php

namespace Tests\Unit\Domain\Content;

use App\Domain\Content\Results\ContentCourseScriptGenerationResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContentCourseScriptGenerationResultTest extends TestCase
{
    public function test_it_normalizes_a_bounded_provider_result_and_derives_duration(): void
    {
        $result = ContentCourseScriptGenerationResult::fromProviderJson(json_encode([
            'exchanges' => [[
                'speakerName' => 'Aki',
                'speakerVoiceId' => 'fishaudio:aki',
                'textL2' => '猫です。',
                'readingL2' => '猫[ねこ]です。',
                'translationL1' => 'It is a cat.',
                'vocabularyItems' => [[
                    'textL2' => '猫', 'readingL2' => 'ねこ', 'translationL1' => 'cat',
                    'complexityScore' => 0.25, 'components' => [['text' => '猫']],
                ]],
            ]],
            'scriptUnits' => [
                ['type' => 'marker', 'label' => 'Start'],
                ['type' => 'narration_L1', 'text' => 'Listen.', 'voiceId' => 'fishaudio:narrator'],
                ['type' => 'pause', 'seconds' => 3],
                [
                    'type' => 'L2', 'text' => '猫です。', 'reading' => 'ねこです。',
                    'translation' => 'It is a cat.', 'voiceId' => 'fishaudio:aki', 'speed' => 1,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('script', $result->pipelinePayload()['_pipelineStage']);
        $this->assertSame(0, $result->coreItems[0]['sourceUnitIndex']);
        $this->assertSame('猫', $result->coreItems[0]['textL2']);
        $this->assertGreaterThanOrEqual(5, $result->estimatedDurationSeconds);
        $this->assertCount(4, $result->scriptUnitsPayload());
    }

    #[DataProvider('invalidProviderResults')]
    public function test_it_rejects_malformed_or_unbounded_provider_results(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentCourseScriptGenerationResult::fromProviderJson($json);
    }

    /** @return array<string, array{string}> */
    public static function invalidProviderResults(): array
    {
        $exchange = [
            'speakerName' => 'Aki', 'speakerVoiceId' => 'voice', 'textL2' => '猫',
            'readingL2' => null, 'translationL1' => 'cat', 'vocabularyItems' => [],
        ];

        return [
            'invalid JSON' => ['{'],
            'missing exchanges' => [json_encode(['scriptUnits' => [['type' => 'pause', 'seconds' => 1]]])],
            'too many exchanges' => [json_encode([
                'exchanges' => array_fill(0, 101, $exchange),
                'scriptUnits' => [['type' => 'pause', 'seconds' => 1]],
            ])],
            'numeric string pause' => [json_encode([
                'exchanges' => [$exchange],
                'scriptUnits' => [['type' => 'pause', 'seconds' => '1']],
            ])],
            'unknown unit type' => [json_encode([
                'exchanges' => [$exchange],
                'scriptUnits' => [['type' => 'audio', 'text' => 'bad']],
            ])],
            'unsupported unit field' => [json_encode([
                'exchanges' => [$exchange],
                'scriptUnits' => [['type' => 'pause', 'seconds' => 1, 'text' => 'ignored']],
            ])],
            'unsupported top-level field' => [json_encode([
                'exchanges' => [$exchange],
                'scriptUnits' => [['type' => 'pause', 'seconds' => 1]],
                'debug' => true,
            ])],
            'out of range speed' => [json_encode([
                'exchanges' => [$exchange],
                'scriptUnits' => [[
                    'type' => 'L2', 'text' => '猫', 'translation' => 'cat',
                    'voiceId' => 'voice', 'speed' => 3,
                ]],
            ])],
            'deep components' => [json_encode([
                'exchanges' => [[
                    ...$exchange,
                    'vocabularyItems' => [[
                        'textL2' => '猫', 'readingL2' => null, 'translationL1' => 'cat',
                        'complexityScore' => 2, 'components' => [[[[[[['too deep']]]]]]],
                    ]],
                ]],
                'scriptUnits' => [['type' => 'pause', 'seconds' => 1]],
            ])],
        ];
    }
}
