<?php

namespace Tests\Unit\Domain\Content;

use App\Domain\Content\Data\GenerateContentImagesData;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GenerateContentImagesDataTest extends TestCase
{
    public function test_direct_input_normalizes_ids_and_applies_the_default_count(): void
    {
        $episodeId = (string) Str::uuid();
        $dialogueId = (string) Str::uuid();

        $data = GenerateContentImagesData::fromInput([
            'episodeId' => ' '.strtoupper($episodeId).' ',
            'dialogueId' => ' '.strtoupper($dialogueId).' ',
        ]);

        $this->assertSame($episodeId, $data->episodeId);
        $this->assertSame($dialogueId, $data->dialogueId);
        $this->assertSame(GenerateContentImagesData::DEFAULT_IMAGE_COUNT, $data->imageCount);
    }

    #[DataProvider('validBoundaryProvider')]
    public function test_direct_input_accepts_image_count_boundaries(int $imageCount): void
    {
        $data = GenerateContentImagesData::fromInput([
            'episodeId' => (string) Str::uuid(),
            'dialogueId' => (string) Str::uuid(),
            'imageCount' => $imageCount,
        ]);

        $this->assertSame($imageCount, $data->imageCount);
    }

    public static function validBoundaryProvider(): array
    {
        return [
            'minimum' => [1],
            'maximum' => [GenerateContentImagesData::MAX_IMAGE_COUNT],
        ];
    }

    #[DataProvider('invalidInputProvider')]
    public function test_direct_input_rejects_invalid_types_and_values(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        GenerateContentImagesData::fromInput([
            'episodeId' => (string) Str::uuid(),
            'dialogueId' => (string) Str::uuid(),
            ...$changes,
        ]);
    }

    public static function invalidInputProvider(): array
    {
        return [
            'missing episode' => [['episodeId' => null]],
            'malformed dialogue' => [['dialogueId' => 'bad']],
            'array dialogue' => [['dialogueId' => ['bad']]],
            'string count' => [['imageCount' => '3']],
            'zero count' => [['imageCount' => 0]],
            'excessive count' => [['imageCount' => GenerateContentImagesData::MAX_IMAGE_COUNT + 1]],
        ];
    }
}
