<?php

namespace App\Domain\Journal\Support;

final class JournalClarificationSchema
{
    public static function definition(): array
    {
        $question = self::object([
            'prompt' => ['type' => 'string'],
            'choices' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]);
        $question['type'] = ['object', 'null'];

        return self::object([
            'resolved' => ['type' => 'boolean'],
            'question' => $question,
            'confirmedMeaning' => ['type' => 'string'],
        ]);
    }

    private static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
