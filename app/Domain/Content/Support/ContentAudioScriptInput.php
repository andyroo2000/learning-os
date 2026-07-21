<?php

namespace App\Domain\Content\Support;

use InvalidArgumentException;

final class ContentAudioScriptInput
{
    public const DEFAULT_VOICE_ID = 'ja-JP-Neural2-D';

    public const MAX_SOURCE_CHARACTERS = 6_000;

    public const MAX_SEGMENTS = 200;

    public const VOICE_IDS = [
        'ja-JP-Neural2-B',
        'ja-JP-Neural2-C',
        'ja-JP-Neural2-D',
    ];

    /** @return array{text: string, reading: string|null, translation: string, imagePrompt: string|null} */
    public static function segment(array $input, int $index): array
    {
        $text = self::requiredString($input['text'] ?? null, "Script segment {$index} text", 2_000);
        $translation = self::requiredString(
            $input['translation'] ?? null,
            "Script segment {$index} translation",
            4_000,
        );

        if (! self::containsJapanese($text)) {
            throw new InvalidArgumentException("Script segment {$index} text must include Japanese.");
        }

        return [
            'text' => $text,
            'reading' => self::optionalString($input['reading'] ?? null, "Script segment {$index} reading", 4_000),
            'translation' => $translation,
            'imagePrompt' => self::optionalString(
                $input['imagePrompt'] ?? null,
                "Script segment {$index} image prompt",
                2_000,
            ),
        ];
    }

    public static function sourceText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Japanese script text is required.');
        }
        if (mb_strlen($value) > self::MAX_SOURCE_CHARACTERS) {
            throw new InvalidArgumentException(
                'Japanese script text must be '.self::MAX_SOURCE_CHARACTERS.' characters or less.',
            );
        }
        if (! self::containsJapanese($value)) {
            throw new InvalidArgumentException('Script text must include Japanese.');
        }

        return $value;
    }

    public static function voiceId(?string $value): string
    {
        $value = trim($value ?? self::DEFAULT_VOICE_ID);
        if (! in_array($value, self::VOICE_IDS, true)) {
            throw new InvalidArgumentException('Script audio requires a Google Neural2 Japanese voice.');
        }

        return $value;
    }

    public static function title(?string $value, string $fallback): string
    {
        $value = trim($value ?? '');

        return mb_substr($value === '' ? $fallback : $value, 0, 120);
    }

    public static function containsJapanese(string $value): bool
    {
        return preg_match('/[\x{3040}-\x{30ff}\x{3400}-\x{9fff}]/u', $value) === 1;
    }

    private static function requiredString(mixed $value, string $label, int $max): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$label} is required.");
        }

        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$label} must contain at most {$max} characters.");
        }

        return $value;
    }

    private static function optionalString(mixed $value, string $label, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} must be a string.");
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$label} must contain at most {$max} characters.");
        }

        return $value;
    }
}
