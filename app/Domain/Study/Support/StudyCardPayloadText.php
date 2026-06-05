<?php

namespace App\Domain\Study\Support;

final class StudyCardPayloadText
{
    private const PROMPT_TEXT_KEYS = [
        'cueText',
        'clozeDisplayText',
        'clozeText',
        'cueMeaning',
        'cueReading',
        'clozeAnswerText',
    ];

    private const ANSWER_TEXT_KEYS = [
        'expression',
        'restoredText',
        'meaning',
        'sentenceEn',
        'notes',
        'expressionReading',
        'restoredTextReading',
        'sentenceJp',
    ];

    private function __construct() {}

    /**
     * @param  array<string, mixed>  $prompt
     */
    public static function frontText(array $prompt): ?string
    {
        return self::firstText($prompt, self::PROMPT_TEXT_KEYS);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    public static function backText(array $answer): ?string
    {
        return self::firstText($answer, self::ANSWER_TEXT_KEYS);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private static function firstText(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
