<?php

namespace App\Domain\Admin\Data;

use InvalidArgumentException;

final readonly class GenerateAdminSentenceScriptData
{
    public const DEFAULT_L1_VOICE_ID = 'fishaudio:ac934b39586e475b83f3277cd97b5cd4';

    public const DEFAULT_L2_VOICE_ID = 'fishaudio:0dff3f6860294829b98f8c4501b2cf25';

    public const MAX_SENTENCE_LENGTH = 15_000;

    public const MAX_PROMPT_LENGTH = 100_000;

    private const VOICE_PATTERN = '/^fishaudio:[a-f0-9]{32}$/';

    private function __construct(
        public string $sentence,
        public ?string $translation,
        public string $targetLanguage,
        public string $nativeLanguage,
        public ?string $jlptLevel,
        public string $l1VoiceId,
        public string $l2VoiceId,
        public ?string $promptOverride,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $sentence = self::requiredString($input['sentence'] ?? null, 'sentence', self::MAX_SENTENCE_LENGTH);
        $translation = self::optionalString($input['translation'] ?? null, 'translation', self::MAX_SENTENCE_LENGTH);
        $targetLanguage = self::language($input['targetLanguage'] ?? 'ja', 'targetLanguage');
        $nativeLanguage = self::language($input['nativeLanguage'] ?? 'en', 'nativeLanguage');
        $jlptLevel = self::optionalString($input['jlptLevel'] ?? null, 'jlptLevel', 32);
        $promptOverride = self::optionalString(
            $input['promptOverride'] ?? null,
            'promptOverride',
            self::MAX_PROMPT_LENGTH,
        );

        return new self(
            sentence: $sentence,
            translation: $translation,
            targetLanguage: $targetLanguage,
            nativeLanguage: $nativeLanguage,
            jlptLevel: $jlptLevel,
            l1VoiceId: self::voiceId($input['l1VoiceId'] ?? self::DEFAULT_L1_VOICE_ID, 'l1VoiceId'),
            l2VoiceId: self::voiceId($input['l2VoiceId'] ?? self::DEFAULT_L2_VOICE_ID, 'l2VoiceId'),
            promptOverride: $promptOverride,
        );
    }

    private static function requiredString(mixed $value, string $field, int $maximum): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$field} is required.");
        }

        return self::boundedString(trim($value), $field, $maximum);
    }

    private static function optionalString(mixed $value, string $field, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$field} must be a string.");
        }

        $value = trim($value);

        return $value === '' ? null : self::boundedString($value, $field, $maximum);
    }

    private static function boundedString(string $value, string $field, int $maximum): string
    {
        if (mb_strlen($value, 'UTF-8') > $maximum) {
            throw new InvalidArgumentException("{$field} is too long.");
        }

        return $value;
    }

    private static function language(mixed $value, string $field): string
    {
        $value = self::requiredString($value, $field, 16);
        if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/i', $value) !== 1) {
            throw new InvalidArgumentException("{$field} is invalid.");
        }

        return strtolower($value);
    }

    private static function voiceId(mixed $value, string $field): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : $value;
        if (! is_string($value) || preg_match(self::VOICE_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a Fish Audio voice ID.");
        }

        return $value;
    }
}
