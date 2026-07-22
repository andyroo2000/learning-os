<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Data\AdminCourseExchangeCollection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminCourseExchangeCollectionTest extends TestCase
{
    public function test_it_normalizes_saved_exchanges_and_builds_legacy_core_items(): void
    {
        $collection = AdminCourseExchangeCollection::fromPipeline([
            '_pipelineStage' => 'exchanges',
            '_exchanges' => [
                $this->exchange([
                    'relationshipName' => '',
                    'vocabularyItems' => [
                        [
                            'textL2' => ' 猫 ',
                            'readingL2' => ' ねこ ',
                            'translationL1' => ' cat ',
                            'jlptLevel' => ' N5 ',
                        ],
                    ],
                ]),
                $this->exchange([
                    'order' => 1,
                    'speakerName' => 'Ken',
                    'speakerVoiceId' => 'voice-two',
                    'vocabularyItems' => [[
                        'textL2' => '犬',
                        'translationL1' => 'dog',
                    ]],
                ]),
            ],
        ]);

        $this->assertSame(['voice-one', 'voice-two'], $collection->speakerVoiceIds());
        $this->assertSame('Aiko', $collection->exchanges[0]['relationshipName']);
        $this->assertSame('猫', $collection->exchanges[0]['vocabularyItems'][0]['textL2']);
        $this->assertSame('N5', $collection->exchanges[0]['vocabularyItems'][0]['jlptLevel']);
        $this->assertSame([
            [
                'textL2' => '猫',
                'readingL2' => 'ねこ',
                'translationL1' => 'cat',
                'complexityScore' => 0,
            ],
            [
                'textL2' => '犬',
                'readingL2' => null,
                'translationL1' => 'dog',
                'complexityScore' => 1,
            ],
        ], $collection->coreItems);
    }

    #[DataProvider('invalidPipelineProvider')]
    public function test_it_rejects_missing_or_malformed_saved_exchanges(mixed $pipeline): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdminCourseExchangeCollection::fromPipeline($pipeline);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPipelineProvider(): iterable
    {
        yield 'missing pipeline' => [null];
        yield 'wrong stage' => [['_pipelineStage' => 'script', '_exchanges' => []]];
        yield 'empty exchanges' => [['_pipelineStage' => 'exchanges', '_exchanges' => []]];
        yield 'too many exchanges' => [[
            '_pipelineStage' => 'exchanges',
            '_exchanges' => array_fill(0, 101, []),
        ]];
        yield 'non-object exchange' => [[
            '_pipelineStage' => 'exchanges',
            '_exchanges' => [[]],
        ]];
        yield 'missing voice' => [[
            '_pipelineStage' => 'exchanges',
            '_exchanges' => [self::validExchange(['speakerVoiceId' => null])],
        ]];
        yield 'too much vocabulary' => [[
            '_pipelineStage' => 'exchanges',
            '_exchanges' => [self::validExchange([
                'vocabularyItems' => array_fill(0, 21, []),
            ])],
        ]];
        yield 'invalid vocabulary reading' => [[
            '_pipelineStage' => 'exchanges',
            '_exchanges' => [self::validExchange([
                'vocabularyItems' => [[
                    'textL2' => '猫',
                    'readingL2' => [],
                    'translationL1' => 'cat',
                ]],
            ])],
        ]];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function exchange(array $overrides = []): array
    {
        return self::validExchange($overrides);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function validExchange(array $overrides = []): array
    {
        return array_merge([
            'order' => 0,
            'speakerName' => 'Aiko',
            'relationshipName' => 'Your friend',
            'speakerVoiceId' => 'voice-one',
            'textL2' => '猫です。',
            'readingL2' => '猫[ねこ]です。',
            'translationL1' => 'It is a cat.',
            'vocabularyItems' => [],
        ], $overrides);
    }
}
