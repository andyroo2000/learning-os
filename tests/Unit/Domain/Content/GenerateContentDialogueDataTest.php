<?php

namespace Tests\Unit\Domain\Content;

use App\Domain\Content\Data\GenerateContentDialogueData;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GenerateContentDialogueDataTest extends TestCase
{
    public function test_it_normalizes_direct_caller_input_and_applies_defaults(): void
    {
        $episodeId = (string) Str::uuid();
        $data = GenerateContentDialogueData::fromInput([
            'episodeId' => '  '.strtoupper($episodeId).'  ',
            'speakers' => [
                ['name' => ' Aiko [あいこ] ', 'voiceId' => ' voice-a ', 'proficiency' => 'N5', 'tone' => 'casual', 'color' => ' #AABBCC '],
                ['name' => ' Ken ', 'voiceId' => ' voice-b ', 'proficiency' => 'native', 'tone' => 'formal'],
            ],
            'vocabSeedOverride' => '  travel  ',
            'grammarSeedOverride' => '   ',
        ]);

        $this->assertSame($episodeId, $data->episodeId);
        $this->assertSame(3, $data->variationCount);
        $this->assertSame(6, $data->dialogueLength);
        $this->assertSame('Aiko [あいこ]', $data->speakers[0]['name']);
        $this->assertSame('voice-a', $data->speakers[0]['voiceId']);
        $this->assertSame('#AABBCC', $data->speakers[0]['color']);
        $this->assertNull($data->speakers[1]['color']);
        $this->assertSame('travel', $data->vocabSeedOverride);
        $this->assertNull($data->grammarSeedOverride);
    }

    public function test_it_accepts_numeric_boundaries(): void
    {
        $minimum = GenerateContentDialogueData::fromInput($this->input([
            'variationCount' => 1,
            'dialogueLength' => 2,
        ]));
        $maximum = GenerateContentDialogueData::fromInput($this->input([
            'variationCount' => 5,
            'dialogueLength' => 20,
        ]));

        $this->assertSame([1, 2], [$minimum->variationCount, $minimum->dialogueLength]);
        $this->assertSame([5, 20], [$maximum->variationCount, $maximum->dialogueLength]);
    }

    #[DataProvider('invalidInputProvider')]
    public function test_it_rejects_invalid_direct_caller_input(array $changes): void
    {
        $input = $this->input();
        foreach ($changes as $key => $value) {
            data_set($input, $key, $value);
        }

        $this->expectException(InvalidArgumentException::class);
        GenerateContentDialogueData::fromInput($input);
    }

    public static function invalidInputProvider(): array
    {
        return [
            'malformed episode' => [['episodeId' => 'bad']],
            'variation below minimum' => [['variationCount' => 0]],
            'variation above maximum' => [['variationCount' => 6]],
            'numeric string' => [['variationCount' => '3']],
            'line count below minimum' => [['dialogueLength' => 1]],
            'line count above maximum' => [['dialogueLength' => 21]],
            'duplicate normalized names' => [['speakers.1.name' => 'aiko [あいこ]']],
            'annotation-only name' => [['speakers.1.name' => '[けん]']],
            'unknown speaker key' => [['speakers.0.secret' => true]],
            'bad color' => [['speakers.0.color' => 'red']],
            'bad JLPT level' => [['jlptLevel' => 'N0']],
            'array override' => [['grammarSeedOverride' => ['N4']]],
        ];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return [
            'episodeId' => (string) Str::uuid(),
            'speakers' => [
                ['name' => 'Aiko', 'voiceId' => 'voice-a', 'proficiency' => 'N4', 'tone' => 'casual', 'color' => null],
                ['name' => 'Ken', 'voiceId' => 'voice-b', 'proficiency' => 'N3', 'tone' => 'polite', 'color' => null],
            ],
            'variationCount' => 3,
            'dialogueLength' => 6,
            ...$overrides,
        ];
    }
}
