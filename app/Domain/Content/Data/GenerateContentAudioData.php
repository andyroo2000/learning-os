<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentDialogueId;
use App\Domain\Content\Support\ContentEpisodeId;
use InvalidArgumentException;

final readonly class GenerateContentAudioData
{
    public const MODE_SINGLE = 'single';

    public const MODE_ALL_SPEEDS = 'all-speeds';

    /** @param 'very-slow'|'slow'|'medium'|'normal' $speed */
    private function __construct(
        public string $episodeId,
        public string $dialogueId,
        public string $mode,
        public string $speed = 'normal',
        public bool $pauseMode = false,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $mode = self::requiredString($input, 'mode');
        if (! in_array($mode, [self::MODE_SINGLE, self::MODE_ALL_SPEEDS], true)) {
            throw new InvalidArgumentException('Audio generation mode is invalid.');
        }
        $speed = array_key_exists('speed', $input) ? self::requiredString($input, 'speed') : 'normal';
        if (! in_array($speed, ['very-slow', 'slow', 'medium', 'normal'], true)) {
            throw new InvalidArgumentException('Audio generation speed is invalid.');
        }
        $pauseMode = $input['pauseMode'] ?? false;
        if (! is_bool($pauseMode)) {
            throw new InvalidArgumentException('Audio generation pause mode must be boolean.');
        }

        return new self(
            episodeId: ContentEpisodeId::normalize(self::requiredString($input, 'episodeId')),
            dialogueId: ContentDialogueId::normalize(self::requiredString($input, 'dialogueId')),
            mode: $mode,
            speed: $speed,
            pauseMode: $pauseMode,
        );
    }

    /** @return array{episodeId: string, dialogueId: string, mode: string, speed: string, pauseMode: bool} */
    public function toArray(): array
    {
        return [
            'episodeId' => $this->episodeId,
            'dialogueId' => $this->dialogueId,
            'mode' => $this->mode,
            'speed' => $this->speed,
            'pauseMode' => $this->pauseMode,
        ];
    }

    /** @param array<string, mixed> $input */
    private static function requiredString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (! is_string($value)) {
            throw new InvalidArgumentException("Audio generation {$key} must be a string.");
        }
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException("Audio generation {$key} is required.");
        }

        return $value;
    }
}
