<?php

namespace App\Domain\Study\Support;

final class StudyCardPayloadSchema
{
    public const VERSION = 1;

    private const PROMPT_STRING_FIELDS = [
        'type',
        'text',
        'cueText',
        'cueReading',
        'cueMeaning',
        'clozeText',
        'clozeDisplayText',
        'clozeAnswerText',
        'clozeHint',
        'clozeResolvedHint',
    ];

    private const PROMPT_MEDIA_FIELDS = [
        'cueAudio',
        'cueImage',
    ];

    private const ANSWER_STRING_FIELDS = [
        'type',
        'text',
        'expression',
        'expressionReading',
        'meaning',
        'notes',
        'sentenceJp',
        'sentenceJpKana',
        'sentenceEn',
        'restoredText',
        'restoredTextReading',
        'answerAudioVoiceId',
        'answerAudioTextOverride',
    ];

    private const ANSWER_MEDIA_FIELDS = [
        'answerAudio',
        'answerImage',
    ];

    private const MEDIA_STRING_FIELDS = [
        'id',
        'filename',
        'url',
        'mediaKind',
        'source',
    ];

    private function __construct() {}

    /**
     * The public schema intentionally permits extension fields so an older API can round-trip
     * cards written by a newer producer. API-owned strings and media fields are typed; the
     * pitch-accent object stays opaque so its resolver can evolve independently.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'urn:convo-lab:schema:study-card-payload:v'.self::VERSION,
            'version' => self::VERSION,
            '$defs' => [
                'nullableString' => ['type' => ['string', 'null']],
                'media' => [
                    'type' => ['object', 'null'],
                    'properties' => self::nullableStringProperties(self::MEDIA_STRING_FIELDS),
                    'additionalProperties' => true,
                ],
                'pitchAccent' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => true,
                ],
            ],
            'type' => 'object',
            'required' => ['prompt', 'answer'],
            'properties' => [
                'prompt' => self::objectSchema(self::PROMPT_STRING_FIELDS, self::PROMPT_MEDIA_FIELDS),
                'answer' => self::objectSchema(
                    self::ANSWER_STRING_FIELDS,
                    self::ANSWER_MEDIA_FIELDS,
                    ['pitchAccent' => ['$ref' => '#/$defs/pitchAccent']],
                ),
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @param  array<string, mixed>  $answer
     * @return array<string, string>
     */
    public static function validationErrors(array $prompt, array $answer): array
    {
        return [
            ...self::payloadValidationErrors('prompt', $prompt, self::PROMPT_STRING_FIELDS, self::PROMPT_MEDIA_FIELDS),
            ...self::payloadValidationErrors('answer', $answer, self::ANSWER_STRING_FIELDS, self::ANSWER_MEDIA_FIELDS),
            ...self::nullableObjectValidationError('answer.pitchAccent', $answer['pitchAccent'] ?? null),
        ];
    }

    /**
     * @param  list<string>  $stringFields
     * @param  list<string>  $mediaFields
     * @param  array<string, mixed>  $extraProperties
     * @return array<string, mixed>
     */
    private static function objectSchema(array $stringFields, array $mediaFields, array $extraProperties = []): array
    {
        $properties = [];

        foreach ($stringFields as $field) {
            $properties[$field] = ['$ref' => '#/$defs/nullableString'];
        }

        foreach ($mediaFields as $field) {
            $properties[$field] = ['$ref' => '#/$defs/media'];
        }

        return [
            'type' => 'object',
            'properties' => [...$properties, ...$extraProperties],
            'additionalProperties' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $stringFields
     * @param  list<string>  $mediaFields
     * @return array<string, string>
     */
    private static function payloadValidationErrors(
        string $payloadName,
        array $payload,
        array $stringFields,
        array $mediaFields,
    ): array {
        if (self::isNonEmptyList($payload)) {
            return [$payloadName => "{$payloadName} must be an object."];
        }

        $errors = self::nullableStringValidationErrors($payloadName, $payload, $stringFields);

        foreach ($mediaFields as $field) {
            $errors = [...$errors, ...self::mediaValidationErrors($payloadName, $payload, $field)];
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    private static function nullableStringValidationErrors(string $path, array $payload, array $fields): array
    {
        $errors = [];

        foreach ($fields as $field) {
            if (self::hasInvalidNullableString($payload, $field)) {
                $errors["{$path}.{$field}"] = "{$path}.{$field} must be a string or null.";
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $payload */
    private static function hasInvalidNullableString(array $payload, string $field): bool
    {
        if (! array_key_exists($field, $payload)) {
            return false;
        }

        if ($payload[$field] === null) {
            return false;
        }

        return ! is_string($payload[$field]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private static function mediaValidationErrors(string $payloadName, array $payload, string $field): array
    {
        $path = "{$payloadName}.{$field}";
        $value = $payload[$field] ?? null;
        $errors = self::nullableObjectValidationError($path, $value);

        if (! self::isNonEmptyObject($value)) {
            return $errors;
        }

        return [
            ...$errors,
            ...self::nullableStringValidationErrors($path, $value, self::MEDIA_STRING_FIELDS),
        ];
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, array{'$ref': string}>
     */
    private static function nullableStringProperties(array $fields): array
    {
        $properties = [];

        foreach ($fields as $field) {
            $properties[$field] = ['$ref' => '#/$defs/nullableString'];
        }

        return $properties;
    }

    /**
     * @return array<string, string>
     */
    private static function nullableObjectValidationError(string $path, mixed $value): array
    {
        // PHP decodes an empty JSON object and an empty JSON array to the same value. Empty media
        // objects are retained for compatibility and validated by the owning media operation.
        if ($value === null) {
            return [];
        }

        if ($value === []) {
            return [];
        }

        if (self::isNonEmptyObject($value)) {
            return [];
        }

        return [$path => "{$path} must be an object or null."];
    }

    /** @param array<array-key, mixed> $value */
    private static function isNonEmptyList(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_is_list($value);
    }

    private static function isNonEmptyObject(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if ($value === []) {
            return false;
        }

        return ! array_is_list($value);
    }
}
