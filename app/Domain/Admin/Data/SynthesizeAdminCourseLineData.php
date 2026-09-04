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
        return new self(
            text: self::text($input['text'] ?? null),
            voiceId: self::voiceId($input['voiceId'] ?? null),
            speed: self::speed($input['speed'] ?? 1),
            unitIndex: self::unitIndex($input['unitIndex'] ?? null),
        );
    }

    private static function text(mixed $value): string
    {
        $text = $value;
        if (! is_string($text) || trim($text) === '') {
            throw new InvalidArgumentException('Line text is required.');
        }

        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT_LENGTH) {
            throw new InvalidArgumentException('Line text is too long.');
        }

        return $text;
    }

    private static function voiceId(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Line voice must be a Fish Audio voice ID.');
        }

        $voiceId = strtolower(trim($value));
        if (preg_match('/^fishaudio:[a-f0-9]{32}$/', $voiceId) !== 1) {
            throw new InvalidArgumentException('Line voice must be a Fish Audio voice ID.');
        }

        return $voiceId;
    }

    private static function speed(mixed $value): float
    {
        if (! is_numeric($value)) {
            self::invalidSpeed();
        }

        $speed = (float) $value;
        if (! is_finite($speed)) {
            self::invalidSpeed();
        }
        if ($speed < 0.5) {
            self::invalidSpeed();
        }
        if ($speed > 2) {
            self::invalidSpeed();
        }

        return $speed;
    }

    private static function unitIndex(mixed $value): int
    {
        $unitIndex = filter_var($value, FILTER_VALIDATE_INT);
        if ($unitIndex === false) {
            self::invalidUnitIndex();
        }
        if ($unitIndex < 0) {
            self::invalidUnitIndex();
        }
        if ($unitIndex > 1_000_000) {
            self::invalidUnitIndex();
        }

        return $unitIndex;
    }

    private static function invalidSpeed(): never
    {
        throw new InvalidArgumentException('Line speed must be between 0.5 and 2.');
    }

    private static function invalidUnitIndex(): never
    {
        throw new InvalidArgumentException('Line unit index must be between 0 and 1000000.');
    }
}
