<?php

namespace Tests\Unit\Domain\Content;

use App\Domain\Content\Data\GenerateContentAudioData;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GenerateContentAudioDataTest extends TestCase
{
    public function test_direct_input_normalizes_ids_and_applies_defaults(): void
    {
        $episodeId = (string) Str::uuid();
        $dialogueId = (string) Str::uuid();

        $data = GenerateContentAudioData::fromInput([
            'episodeId' => ' '.strtoupper($episodeId).' ',
            'dialogueId' => ' '.strtoupper($dialogueId).' ',
            'mode' => 'single',
        ]);

        $this->assertSame($episodeId, $data->episodeId);
        $this->assertSame($dialogueId, $data->dialogueId);
        $this->assertSame('normal', $data->speed);
        $this->assertFalse($data->pauseMode);
    }

    #[DataProvider('invalidInputProvider')]
    public function test_direct_input_rejects_invalid_types_and_values(array $changes): void
    {
        $input = [
            'episodeId' => (string) Str::uuid(),
            'dialogueId' => (string) Str::uuid(),
            'mode' => 'single',
            ...$changes,
        ];

        $this->expectException(InvalidArgumentException::class);
        GenerateContentAudioData::fromInput($input);
    }

    public static function invalidInputProvider(): array
    {
        return [
            'missing episode' => [['episodeId' => null]],
            'malformed episode' => [['episodeId' => 'bad']],
            'array dialogue' => [['dialogueId' => ['bad']]],
            'unknown mode' => [['mode' => 'batch']],
            'blank mode' => [['mode' => ' ']],
            'unknown speed' => [['speed' => 'fast']],
            'array speed' => [['speed' => ['normal']]],
            'coerced pause' => [['pauseMode' => 1]],
        ];
    }
}
