<?php

namespace App\Domain\Admin\Data;

use InvalidArgumentException;

final readonly class SynthesizeAdminCourseLineData
{
    public const MAX_TEXT_LENGTH = 15_000;

    private function __construct(
        public string $text,
        public string $voiceId,
        public float $speed,
        public int $unitIndex,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $text = $input['text'] ?? null;
        if (! is_string($text) || trim($text) === '') {
            throw new InvalidArgumentException('Line text is required.');
        }
        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT_LENGTH) {
            throw new InvalidArgumentException('Line text is too long.');
        }

        $voiceId = $input['voiceId'] ?? null;
        $voiceId = is_string($voiceId) ? strtolower(trim($voiceId)) : $voiceId;
        if (! is_string($voiceId)
            || preg_match('/^fishaudio:[a-f0-9]{32}$/', $voiceId) !== 1) {
            throw new InvalidArgumentException('Line voice must be a Fish Audio voice ID.');
        }

        $speed = $input['speed'] ?? 1;
        if ((! is_int($speed) && ! is_float($speed) && ! is_string($speed))
            || ! is_numeric($speed)
            || ! is_finite((float) $speed)
            || (float) $speed < 0.5
            || (float) $speed > 2) {
            throw new InvalidArgumentException('Line speed must be between 0.5 and 2.');
        }

        $unitIndex = filter_var($input['unitIndex'] ?? null, FILTER_VALIDATE_INT);
        if ($unitIndex === false || $unitIndex < 0 || $unitIndex > 1_000_000) {
            throw new InvalidArgumentException('Line unit index must be between 0 and 1000000.');
        }

        return new self(
            text: $text,
            voiceId: $voiceId,
            speed: (float) $speed,
            unitIndex: $unitIndex,
        );
    }
}
