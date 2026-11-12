<?php

namespace App\Domain\Journal\Support;

final class JournalFeedbackSchema
{
    public static function definition(): array
    {
        $string = ['type' => 'string'];
        $nullable = ['type' => ['string', 'null']];

        return self::object([
            'title' => $string,
            'correctedText' => $string,
            'correctedReading' => $string,
            'question' => $string,
            'questionReading' => $string,
            'questionTranslation' => $string,
            'segments' => ['type' => 'array', 'items' => self::object([
                'text' => $string,
                'reading' => $string,
                'kind' => ['type' => 'string', 'enum' => ['plain', 'correction', 'optional']],
                'suggestion' => $nullable,
                'suggestionReading' => $nullable,
                'explanation' => $nullable,
                'explanationReading' => $nullable,
            ])],
        ]);
    }

    private static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
