<?php

namespace App\Domain\Journal\Support;

final class JournalCompactSchema
{
    public static function definition(): array
    {
        $string = ['type' => 'string'];
        $nullable = ['type' => ['string', 'null']];

        return self::object([
            'title' => $string, 'questionReading' => $string, 'questionTranslation' => $string,
            'segments' => ['type' => 'array', 'items' => self::object([
                'reading' => $string,
                'kind' => ['type' => 'string', 'enum' => ['plain', 'correction', 'optional']],
                'suggestionReading' => $nullable, 'explanationReading' => $nullable, 'replacementReading' => $nullable,
            ])],
        ]);
    }

    public static function combined(): array
    {
        $schema = JournalClarificationSchema::definition();
        $schema['properties']['feedback'] = ['anyOf' => [self::definition(), ['type' => 'null']]];
        $schema['required'][] = 'feedback';

        return $schema;
    }

    private static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
