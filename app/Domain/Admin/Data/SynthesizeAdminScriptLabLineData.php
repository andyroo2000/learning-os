<?php

namespace App\Domain\Admin\Data;

use App\Support\Audio\FishAudioSpeechGenerator;
use InvalidArgumentException;

final readonly class SynthesizeAdminScriptLabLineData
{
    private function __construct(
        public string $text,
        public string $voiceId,
        public float $speed,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        return new self(
            text: self::text($input['text'] ?? null),
            voiceId: self::voiceId($input['voiceId'] ?? null),
            speed: self::speed($input['speed'] ?? 1),
        );
    }

    private static function text(mixed $value): string
    {
        $text = $value;
        if (! is_string($text) || trim($text) === '') {
            throw new InvalidArgumentException('Line text is required.');
        }

        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') > FishAudioSpeechGenerator::MAX_TEXT_LENGTH) {
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

    private static function invalidSpeed(): never
    {
        throw new InvalidArgumentException('Line speed must be between 0.5 and 2.');
    }
}
