<?php

namespace App\Domain\Study\Support;

/** Canonical field names are shared across languages and front presentations. */
final class StudyImportCanonicalAnswer
{
    private const FIELDS = [
        'expression' => 'expression',
        'expressionreading' => 'expressionReading',
        'meaning' => 'meaning',
        'notes' => 'notes',
    ];

    /** @param list<string> $names
     * @return array<string, string|null>|null
     */
    public static function fromFields(array $names, string $fields): ?array
    {
        $values = explode("\x1f", $fields);
        if (count($names) !== count($values)) {
            return null;
        }

        $answer = [];
        foreach ($names as $index => $name) {
            $key = self::FIELDS[strtolower(trim($name))] ?? null;
            if ($key === null) {
                continue;
            }
            if (array_key_exists($key, $answer)) {
                return null;
            }
            $answer[$key] = self::text($values[$index]);
        }

        if (! isset($answer['expression'], $answer['meaning'])) {
            return null;
        }

        return $answer;
    }

    private static function text(string $value): ?string
    {
        $value = preg_replace('/<br\s*\/?\s*>/iu', "\n", $value) ?? $value;
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\[sound:[^\]\r\n]+\]/i', '', $value) ?? $value;
        $value = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $value) ?? $value;

        return $value === '' ? null : $value;
    }
}
