<?php

namespace App\Domain\Admin\Results;

use InvalidArgumentException;
use JsonException;

final readonly class AdminCourseDialogueResult
{
    private const MAX_RESPONSE_BYTES = 1_000_000;

    private const MAX_EXCHANGES = 100;

    private const array SPEAKER_NAME = ['label' => 'Dialogue speaker name', 'max' => 100];

    private const array RELATIONSHIP_NAME = ['label' => 'Dialogue relationship name', 'max' => 255];

    private const array TEXT = ['label' => 'Dialogue text', 'max' => 5_000];

    private const array READING = ['label' => 'Dialogue reading', 'max' => 10_000];

    private const array TRANSLATION = ['label' => 'Dialogue translation', 'max' => 5_000];

    private const array VOCABULARY_WORD = ['label' => 'Dialogue vocabulary word', 'max' => 1_000];

    private const array VOCABULARY_TRANSLATION = ['label' => 'Dialogue vocabulary translation', 'max' => 2_000];

    private const array VOCABULARY_READING = ['label' => 'Dialogue vocabulary reading', 'max' => 2_000];

    private const array VOCABULARY_LEVEL = ['label' => 'Dialogue vocabulary JLPT level', 'max' => 8];

    /** @param list<array<string, mixed>> $exchanges */
    private function __construct(public array $exchanges) {}

    /**
     * @param  list<array{speakerName: string, voiceId: string}>  $existingVoices
     */
    public static function fromJson(
        string $json,
        array $existingVoices,
        string $speaker1VoiceId,
        string $speaker2VoiceId,
    ): self {
        $rawExchanges = self::decodeExchanges($json);

        $voiceMap = [];
        $voiceIndex = 0;
        $defaults = [$speaker1VoiceId, $speaker2VoiceId];
        $exchanges = [];
        foreach ($rawExchanges as $rawExchange) {
            $rawExchange = self::exchangeObject($rawExchange);
            $speakerName = self::requiredString($rawExchange['speakerName'] ?? null, self::SPEAKER_NAME);
            $voiceMapKey = mb_strtolower($speakerName);
            if (! array_key_exists($voiceMapKey, $voiceMap)) {
                $voiceMap[$voiceMapKey] = self::existingVoice($existingVoices, $speakerName)
                    ?? $defaults[$voiceIndex++ % count($defaults)];
            }

            $exchanges[] = self::exchange($rawExchange, $speakerName, $voiceMap[$voiceMapKey]);
        }

        return new self($exchanges);
    }

    /** @return list<mixed> */
    private static function decodeExchanges(string $json): array
    {
        if (strlen($json) > self::MAX_RESPONSE_BYTES) {
            throw new InvalidArgumentException('Dialogue provider response is too large.');
        }

        $json = trim($json);
        if (preg_match('/```(?:json)?\s*\n?([\s\S]*?)\n?```/i', $json, $matches) === 1) {
            $json = trim($matches[1]);
        }
        try {
            $decoded = json_decode($json, true, 20, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Dialogue provider response must be valid JSON.', 0, $exception);
        }

        self::ensureResponseEnvelope($decoded);

        return self::exchangeList($decoded['exchanges']);
    }

    /** @return list<mixed> */
    private static function exchangeList(mixed $exchanges): array
    {
        if (! is_array($exchanges)) {
            throw new InvalidArgumentException('Dialogue provider exchanges are invalid.');
        }
        if (! array_is_list($exchanges)) {
            throw new InvalidArgumentException('Dialogue provider exchanges are invalid.');
        }
        if (count($exchanges) > self::MAX_EXCHANGES) {
            throw new InvalidArgumentException('Dialogue provider exchanges are invalid.');
        }

        return $exchanges;
    }

    private static function ensureResponseEnvelope(mixed $decoded): void
    {
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Dialogue provider response shape is invalid.');
        }
        if (array_is_list($decoded)) {
            throw new InvalidArgumentException('Dialogue provider response shape is invalid.');
        }
        if (array_keys($decoded) !== ['exchanges']) {
            throw new InvalidArgumentException('Dialogue provider response shape is invalid.');
        }
    }

    /** @return array<string, mixed> */
    private static function exchangeObject(mixed $exchange): array
    {
        if (! is_array($exchange)) {
            throw new InvalidArgumentException('Dialogue provider exchange must be an object.');
        }
        if (array_is_list($exchange)) {
            throw new InvalidArgumentException('Dialogue provider exchange must be an object.');
        }

        return $exchange;
    }

    /**
     * @param  array<string, mixed>  $rawExchange
     * @return array<string, mixed>
     */
    private static function exchange(array $rawExchange, string $speakerName, string $voiceId): array
    {
        return [
            'order' => self::integer($rawExchange['order'] ?? null, 'Dialogue exchange order'),
            'speakerName' => $speakerName,
            'relationshipName' => self::optionalString(
                $rawExchange['relationshipName'] ?? null,
                self::RELATIONSHIP_NAME,
            ) ?? $speakerName,
            'speakerVoiceId' => $voiceId,
            'textL2' => self::requiredString($rawExchange['textL2'] ?? null, self::TEXT),
            'readingL2' => self::optionalString(
                $rawExchange['reading'] ?? null,
                self::READING,
            ),
            'translationL1' => self::requiredString(
                $rawExchange['translation'] ?? null,
                self::TRANSLATION,
            ),
            'vocabularyItems' => self::vocabulary($rawExchange['vocabulary'] ?? []),
        ];
    }

    /**
     * @param  list<array{speakerName: string, voiceId: string}>  $voices
     */
    private static function existingVoice(array $voices, string $speakerName): ?string
    {
        foreach ($voices as $voice) {
            if (mb_strtolower($voice['speakerName']) === mb_strtolower($speakerName)) {
                return trim($voice['voiceId']) === '' ? null : $voice['voiceId'];
            }
        }

        return null;
    }

    /** @return list<array{textL2: string, readingL2?: string, translationL1: string, jlptLevel?: string}> */
    private static function vocabulary(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Dialogue vocabulary is invalid.');
        }
        if (! array_is_list($value)) {
            throw new InvalidArgumentException('Dialogue vocabulary is invalid.');
        }
        if (count($value) > 20) {
            throw new InvalidArgumentException('Dialogue vocabulary is invalid.');
        }

        $items = [];
        foreach ($value as $rawItem) {
            $items[] = self::vocabularyItem($rawItem);
        }

        return $items;
    }

    /** @return array{textL2: string, readingL2?: string, translationL1: string, jlptLevel?: string} */
    private static function vocabularyItem(mixed $rawItem): array
    {
        if (! is_array($rawItem)) {
            throw new InvalidArgumentException('Dialogue vocabulary item must be an object.');
        }
        if (array_is_list($rawItem)) {
            throw new InvalidArgumentException('Dialogue vocabulary item must be an object.');
        }

        $item = [
            'textL2' => self::vocabularyWord($rawItem['word'] ?? null),
            'translationL1' => self::requiredString(
                $rawItem['translation'] ?? null,
                self::VOCABULARY_TRANSLATION,
            ),
        ];
        $optionalValues = [
            'readingL2' => self::optionalString(
                $rawItem['reading'] ?? null,
                self::VOCABULARY_READING,
            ),
            'jlptLevel' => self::optionalString(
                $rawItem['jlptLevel'] ?? null,
                self::VOCABULARY_LEVEL,
            ),
        ];
        foreach ($optionalValues as $key => $value) {
            if ($value !== null) {
                $item[$key] = $value;
            }
        }

        return $item;
    }

    private static function vocabularyWord(mixed $value): string
    {
        $word = self::requiredString($value, self::VOCABULARY_WORD);
        $word = trim((string) preg_replace('/\s*[（(][^)）]*[)）]\s*/u', '', $word));
        if ($word === '') {
            throw new InvalidArgumentException('Dialogue vocabulary word is invalid.');
        }

        return $word;
    }

    /** @param array{label: string, max: int} $field */
    private static function requiredString(mixed $value, array $field): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException(self::fieldLabel($field).' must be a string.');
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException(self::fieldLabel($field).' is invalid.');
        }

        return self::validatedStringLength($value, $field);
    }

    /** @param array{label: string, max: int} $field */
    private static function optionalString(mixed $value, array $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(self::fieldLabel($field).' must be a string or null.');
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return self::validatedStringLength($value, $field);
    }

    /** @param array{label: string, max: int} $field */
    private static function validatedStringLength(string $value, array $field): string
    {
        if (mb_strlen($value) > self::fieldMaximum($field)) {
            throw new InvalidArgumentException(self::fieldLabel($field).' is invalid.');
        }

        return $value;
    }

    /** @param array{label: string, max: int} $field */
    private static function fieldLabel(array $field): string
    {
        return $field['label'];
    }

    /** @param array{label: string, max: int} $field */
    private static function fieldMaximum(array $field): int
    {
        return $field['max'];
    }

    private static function integer(mixed $value, string $label): int
    {
        if (! is_int($value)) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }
        if ($value < 0) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }
        if ($value > self::MAX_EXCHANGES) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }

        return $value;
    }
}
