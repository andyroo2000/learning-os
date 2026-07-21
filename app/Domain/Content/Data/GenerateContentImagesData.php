<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentDialogueId;
use App\Domain\Content\Support\ContentEpisodeId;
use InvalidArgumentException;

final readonly class GenerateContentImagesData
{
    public const DEFAULT_IMAGE_COUNT = 3;

    public const MAX_IMAGE_COUNT = 10;

    private function __construct(
        public string $episodeId,
        public string $dialogueId,
        public int $imageCount,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $episodeId = $input['episodeId'] ?? null;
        $dialogueId = $input['dialogueId'] ?? null;
        $imageCount = $input['imageCount'] ?? self::DEFAULT_IMAGE_COUNT;
        if (! is_string($episodeId) || ! is_string($dialogueId)) {
            throw new InvalidArgumentException('Image generation requires episode and dialogue IDs.');
        }
        if (! is_int($imageCount) || $imageCount < 1 || $imageCount > self::MAX_IMAGE_COUNT) {
            throw new InvalidArgumentException('Image count must be between 1 and '.self::MAX_IMAGE_COUNT.'.');
        }

        return new self(
            ContentEpisodeId::normalize($episodeId),
            ContentDialogueId::normalize($dialogueId),
            $imageCount,
        );
    }

    /** @return array{episodeId: string, dialogueId: string, imageCount: int} */
    public function toArray(): array
    {
        return [
            'episodeId' => $this->episodeId,
            'dialogueId' => $this->dialogueId,
            'imageCount' => $this->imageCount,
        ];
    }
}
