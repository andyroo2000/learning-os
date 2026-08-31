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
        if ($payload !== [] && array_is_list($payload)) {
            return [$payloadName => "{$payloadName} must be an object."];
        }

        $errors = [];

        foreach ($stringFields as $field) {
            if (array_key_exists($field, $payload) && ! is_string($payload[$field]) && $payload[$field] !== null) {
                $errors["{$payloadName}.{$field}"] = "{$payloadName}.{$field} must be a string or null.";
            }
        }

        foreach ($mediaFields as $field) {
            $path = "{$payloadName}.{$field}";
            $value = $payload[$field] ?? null;
            $errors = [...$errors, ...self::nullableObjectValidationError($path, $value)];

            if (! is_array($value) || array_is_list($value)) {
                continue;
            }

            foreach (self::MEDIA_STRING_FIELDS as $mediaField) {
                if (array_key_exists($mediaField, $value)
                    && ! is_string($value[$mediaField])
                    && $value[$mediaField] !== null) {
                    $errors["{$path}.{$mediaField}"] = "{$path}.{$mediaField} must be a string or null.";
                }
            }
        }

        return $errors;
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
        if ($value === null || (is_array($value) && ($value === [] || ! array_is_list($value)))) {
            return [];
        }

        return [$path => "{$path} must be an object or null."];
    }
}
