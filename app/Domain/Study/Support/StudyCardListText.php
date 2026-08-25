<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Models\Card;

final class StudyCardListText
{
    private function __construct() {}

    public static function displayText(Card $card): string
    {
        return self::payloadField($card->prompt_json, [
            'clozeDisplayText',
            'cueText',
        ])
            ?? self::payloadField($card->answer_json, [
                'expressionReading',
                'expression',
                'restoredText',
                'meaning',
            ])
            ?? self::payloadField($card->prompt_json, ['clozeText'])
            ?? self::stringField($card->front_text)
            ?? 'Untitled card';
    }

    public static function meaning(Card $card): ?string
    {
        return self::payloadField($card->answer_json, ['meaning', 'sentenceEn'])
            ?? self::payloadField($card->prompt_json, ['cueMeaning'])
            ?? self::stringField($card->back_text);
    }

    /**
     * @param  list<string>  $keys
     */
    private static function payloadField(mixed $payload, array $keys): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach ($keys as $key) {
            $value = self::stringField($payload[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function stringField(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
