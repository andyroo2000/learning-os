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
        $text = $input['text'] ?? null;
        if (! is_string($text) || trim($text) === '') {
            throw new InvalidArgumentException('Pronunciation test text is required.');
        }
        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') > FishAudioSpeechGenerator::MAX_TEXT_LENGTH) {
            throw new InvalidArgumentException('Pronunciation test text is too long.');
        }

        $format = $input['format'] ?? null;
        $format = is_string($format) ? strtolower(trim($format)) : $format;
        if (! is_string($format) || ! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException('Pronunciation test format is invalid.');
        }

        $voiceId = $input['voiceId'] ?? null;
        $voiceId = is_string($voiceId) ? strtolower(trim($voiceId)) : $voiceId;
        if (! is_string($voiceId) || preg_match('/^fishaudio:[a-f0-9]{32}$/', $voiceId) !== 1) {
            throw new InvalidArgumentException('Pronunciation test voice must be a Fish Audio voice ID.');
        }

        $speed = $input['speed'] ?? 1;
        if ((! is_int($speed) && ! is_float($speed) && ! is_string($speed))
            || ! is_numeric($speed)
            || ! is_finite((float) $speed)
            || (float) $speed < 0.5
            || (float) $speed > 2) {
            throw new InvalidArgumentException('Pronunciation test speed must be between 0.5 and 2.');
        }

        return new self($text, $format, $voiceId, (float) $speed);
    }

    public function requiresPreprocessing(): bool
    {
        return in_array($this->format, ['kana', 'furigana_brackets'], true);
    }
}
