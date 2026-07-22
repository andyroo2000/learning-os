<?php

namespace App\Domain\Admin\Results;

use InvalidArgumentException;
use JsonException;

final readonly class AdminCourseDialogueResult
{
    private const MAX_RESPONSE_BYTES = 1_000_000;

    private const MAX_EXCHANGES = 100;

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
        if (strlen($json) > self::MAX_RESPONSE_BYTES) {
            throw new InvalidArgumentException('Dialogue provider response is too large.');
        }

        $json = self::stripCodeFence(trim($json));
        try {
            $decoded = json_decode($json, true, 20, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Dialogue provider response must be valid JSON.', 0, $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded) || array_keys($decoded) !== ['exchanges']) {
            throw new InvalidArgumentException('Dialogue provider response shape is invalid.');
        }
        $rawExchanges = $decoded['exchanges'];
        if (! is_array($rawExchanges) || ! array_is_list($rawExchanges) || count($rawExchanges) > self::MAX_EXCHANGES) {
            throw new InvalidArgumentException('Dialogue provider exchanges are invalid.');
        }

        $voiceMap = [];
        $voiceIndex = 0;
        $defaults = [$speaker1VoiceId, $speaker2VoiceId];
        $exchanges = [];
        foreach ($rawExchanges as $rawExchange) {
            if (! is_array($rawExchange) || array_is_list($rawExchange)) {
                throw new InvalidArgumentException('Dialogue provider exchange must be an object.');
            }

            $speakerName = self::string($rawExchange['speakerName'] ?? null, 'Dialogue speaker name', 100);
            if (! array_key_exists($speakerName, $voiceMap)) {
                $voiceMap[$speakerName] = self::existingVoice($existingVoices, $speakerName)
                    ?? $defaults[$voiceIndex++ % count($defaults)];
            }

            $exchanges[] = [
                'order' => self::integer($rawExchange['order'] ?? null, 'Dialogue exchange order'),
                'speakerName' => $speakerName,
                'relationshipName' => self::optionalString(
                    $rawExchange['relationshipName'] ?? null,
                    'Dialogue relationship name',
                    255,
                ) ?? $speakerName,
                'speakerVoiceId' => $voiceMap[$speakerName],
                'textL2' => self::string($rawExchange['textL2'] ?? null, 'Dialogue text', 5_000),
                'readingL2' => self::optionalString(
                    $rawExchange['reading'] ?? null,
                    'Dialogue reading',
                    10_000,
                ),
                'translationL1' => self::string(
                    $rawExchange['translation'] ?? null,
                    'Dialogue translation',
                    5_000,
                ),
                'vocabularyItems' => self::vocabulary($rawExchange['vocabulary'] ?? []),
            ];
        }

        return new self($exchanges);
    }

    private static function stripCodeFence(string $json): string
    {
        if (preg_match('/```(?:json)?\s*\n?([\s\S]*?)\n?```/i', $json, $matches) === 1) {
            return trim($matches[1]);
        }

        return $json;
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
        if (! is_array($value) || ! array_is_list($value) || count($value) > 20) {
            throw new InvalidArgumentException('Dialogue vocabulary is invalid.');
        }

        $items = [];
        foreach ($value as $rawItem) {
            if (! is_array($rawItem) || array_is_list($rawItem)) {
                throw new InvalidArgumentException('Dialogue vocabulary item must be an object.');
            }

            $word = self::string($rawItem['word'] ?? null, 'Dialogue vocabulary word', 1_000);
            $word = trim((string) preg_replace('/\s*[（(][^)）]*[)）]\s*/u', '', $word));
            if ($word === '') {
                throw new InvalidArgumentException('Dialogue vocabulary word is invalid.');
            }
            $item = [
                'textL2' => $word,
                'translationL1' => self::string(
                    $rawItem['translation'] ?? null,
                    'Dialogue vocabulary translation',
                    2_000,
                ),
            ];
            $reading = self::optionalString($rawItem['reading'] ?? null, 'Dialogue vocabulary reading', 2_000);
            $level = self::optionalString($rawItem['jlptLevel'] ?? null, 'Dialogue vocabulary JLPT level', 8);
            if ($reading !== null) {
                $item['readingL2'] = $reading;
            }
            if ($level !== null) {
                $item['jlptLevel'] = $level;
            }
            $items[] = $item;
        }

        return $items;
    }

    private static function string(mixed $value, string $label, int $max): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} must be a string.");
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }

        return $value;
    }

    private static function optionalString(mixed $value, string $label, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} must be a string or null.");
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }

        return $value;
    }

    private static function integer(mixed $value, string $label): int
    {
        if (! is_int($value) || $value < 0 || $value > self::MAX_EXCHANGES) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }

        return $value;
    }
}
