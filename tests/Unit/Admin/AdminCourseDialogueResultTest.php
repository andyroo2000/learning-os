<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Results\AdminCourseDialogueResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminCourseDialogueResultTest extends TestCase
{
    public function test_it_normalizes_legacy_optional_values_and_voice_assignment(): void
    {
        $json = <<<'JSON'
```json
{"exchanges":[{"order":0,"speakerName":"Aiko","relationshipName":"","textL2":"猫です。","reading":"","translation":"It is a cat.","vocabulary":[{"word":"猫 (neko)","reading":"","translation":"cat","jlptLevel":""}]},{"order":1,"speakerName":"Ken","textL2":"はい。","translation":"Yes."}]}
```
JSON;

        $result = AdminCourseDialogueResult::fromJson(
            $json,
            [['speakerName' => 'AIKO', 'voiceId' => 'existing']],
            'speaker-one',
            'speaker-two',
        );

        $this->assertSame('Aiko', $result->exchanges[0]['relationshipName']);
        $this->assertSame('existing', $result->exchanges[0]['speakerVoiceId']);
        $this->assertNull($result->exchanges[0]['readingL2']);
        $this->assertSame([
            'textL2' => '猫',
            'translationL1' => 'cat',
        ], $result->exchanges[0]['vocabularyItems'][0]);
        $this->assertSame('speaker-one', $result->exchanges[1]['speakerVoiceId']);
        $this->assertSame('Ken', $result->exchanges[1]['relationshipName']);
        $this->assertArrayHasKey('readingL2', $result->exchanges[1]);
    }

    #[DataProvider('invalidResponseProvider')]
    public function test_it_rejects_unbounded_or_malformed_provider_responses(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdminCourseDialogueResult::fromJson($json, [], 'one', 'two');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidResponseProvider(): iterable
    {
        yield 'too many exchanges' => [json_encode([
            'exchanges' => array_fill(0, 101, []),
        ], JSON_THROW_ON_ERROR)];
        yield 'response byte limit' => [str_repeat('x', 1_000_001)];
        yield 'extra top-level key' => ['{"exchanges":[],"extra":true}'];
        yield 'non-list exchanges' => ['{"exchanges":{"0":{}}}'];
        yield 'negative order' => [self::exchangeJson(['order' => -1])];
        yield 'oversized text' => [self::exchangeJson(['textL2' => str_repeat('x', 5_001)])];
        yield 'too much vocabulary' => [self::exchangeJson([
            'vocabulary' => array_fill(0, 21, [
                'word' => '猫',
                'translation' => 'cat',
            ]),
        ])];
        yield 'invalid optional reading' => [self::exchangeJson(['reading' => []])];
    }

    /** @param array<string, mixed> $overrides */
    private static function exchangeJson(array $overrides): string
    {
        return json_encode([
            'exchanges' => [array_merge([
                'order' => 0,
                'speakerName' => 'Aiko',
                'textL2' => '猫です。',
                'translation' => 'It is a cat.',
                'vocabulary' => [],
            ], $overrides)],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
