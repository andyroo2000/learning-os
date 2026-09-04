<?php

namespace App\Domain\Admin\Data;

use App\Support\Audio\FishAudioSpeechGenerator;
use InvalidArgumentException;

final readonly class TestAdminPronunciationData
{
    public const FORMATS = ['kanji', 'kana', 'mixed', 'furigana_brackets'];

    private function __construct(
        public string $text,
        public string $format,
        public string $voiceId,
        public float $speed,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        return new self(
            text: self::text($input['text'] ?? null),
            format: self::format($input['format'] ?? null),
            voiceId: self::voiceId($input['voiceId'] ?? null),
            speed: self::speed($input['speed'] ?? 1),
        );
    }

    private static function text(mixed $value): string
    {
        $text = $value;
        if (! is_string($text) || trim($text) === '') {
            throw new InvalidArgumentException('Pronunciation test text is required.');
        }

        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') > FishAudioSpeechGenerator::MAX_TEXT_LENGTH) {
            throw new InvalidArgumentException('Pronunciation test text is too long.');
        }

        return $text;
    }

    private static function format(mixed $value): string
    {
        $format = self::normalizedString($value, 'Pronunciation test format is invalid.');
        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException('Pronunciation test format is invalid.');
        }

        return $format;
    }

    private static function voiceId(mixed $value): string
    {
        $message = 'Pronunciation test voice must be a Fish Audio voice ID.';
        $voiceId = self::normalizedString($value, $message);
        if (preg_match('/^fishaudio:[a-f0-9]{32}$/', $voiceId) !== 1) {
            throw new InvalidArgumentException($message);
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

    private static function normalizedString(mixed $value, string $message): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException($message);
        }

        return strtolower(trim($value));
    }

    private static function invalidSpeed(): never
    {
        throw new InvalidArgumentException('Pronunciation test speed must be between 0.5 and 2.');
    }

    public function requiresPreprocessing(): bool
    {
        return in_array($this->format, ['kana', 'furigana_brackets'], true);
    }
}
