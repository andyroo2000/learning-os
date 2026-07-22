<?php

namespace App\Domain\Admin\Results;

use App\Domain\Admin\Data\GenerateAdminSentenceScriptData;
use JsonException;

final readonly class AdminSentenceScriptResult
{
    private const MAX_UNITS = 1_000;

    /** @param list<array<string, mixed>>|null $units */
    private function __construct(
        public ?array $units,
        public ?float $estimatedDurationSeconds,
        public string $rawResponse,
        public string $resolvedPrompt,
        public ?string $translation,
        public ?string $parseError,
    ) {}

    public static function fromProviderResponse(
        string $rawResponse,
        string $resolvedPrompt,
        GenerateAdminSentenceScriptData $data,
    ): self {
        try {
            $parsed = json_decode(self::stripMarkdownFence($rawResponse), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return new self(
                units: null,
                estimatedDurationSeconds: null,
                rawResponse: $rawResponse,
                resolvedPrompt: $resolvedPrompt,
                translation: $data->translation,
                parseError: $exception->getMessage(),
            );
        }

        $rawUnits = is_array($parsed) && array_is_list($parsed)
            ? $parsed
            : (is_array($parsed) ? ($parsed['units'] ?? null) : null);
        $translation = $data->translation;
        if (is_array($parsed) && ! array_is_list($parsed)) {
            $generatedTranslation = $parsed['translation'] ?? null;
            if (is_string($generatedTranslation) && trim($generatedTranslation) !== '') {
                $translation = trim($generatedTranslation);
            }
        }

        $units = self::normalizeUnits($rawUnits, $data);

        return new self(
            units: $units,
            estimatedDurationSeconds: self::estimateDuration($units),
            rawResponse: $rawResponse,
            resolvedPrompt: $resolvedPrompt,
            translation: $translation,
            parseError: null,
        );
    }

    private static function stripMarkdownFence(string $response): string
    {
        $response = trim($response);
        if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $response, $match) === 1) {
            return trim($match[1]);
        }

        return $response;
    }

    /** @return list<array<string, mixed>> */
    private static function normalizeUnits(mixed $rawUnits, GenerateAdminSentenceScriptData $data): array
    {
        if (! is_array($rawUnits) || ! array_is_list($rawUnits)) {
            return [];
        }

        $units = [];
        foreach (array_slice($rawUnits, 0, self::MAX_UNITS) as $rawUnit) {
            $unit = is_array($rawUnit) ? self::normalizeUnit($rawUnit, $data) : null;
            if ($unit !== null) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    /** @param array<string, mixed> $unit @return array<string, mixed>|null */
    private static function normalizeUnit(array $unit, GenerateAdminSentenceScriptData $data): ?array
    {
        $type = is_string($unit['type'] ?? null)
            ? strtolower(str_replace('-', '_', trim($unit['type'])))
            : '';

        if (in_array($type, ['narration', 'narration_l1'], true)) {
            $text = self::nonEmptyString($unit['text'] ?? null);

            return $text === null ? null : [
                'type' => 'narration_L1',
                'text' => $text,
                'voiceId' => $data->l1VoiceId,
            ];
        }

        if ($type === 'l2') {
            $text = self::nonEmptyString($unit['text'] ?? null);
            if ($text === null) {
                return null;
            }
            $reading = self::nonEmptyString($unit['reading'] ?? null);
            $translation = self::nonEmptyString($unit['translation'] ?? null);
            $speed = self::number($unit['speed'] ?? null);

            return array_filter([
                'type' => 'L2',
                'text' => $text,
                'reading' => $reading,
                'translation' => $translation,
                'voiceId' => $data->l2VoiceId,
                'speed' => $speed !== null && $speed >= 0.5 && $speed <= 2 ? $speed : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        if ($type === 'pause') {
            $seconds = self::number($unit['seconds'] ?? $unit['durationSeconds'] ?? $unit['duration'] ?? null);

            return $seconds === null ? null : [
                'type' => 'pause',
                'seconds' => max(0, min(60, $seconds)),
            ];
        }

        if ($type === 'marker') {
            $label = self::nonEmptyString($unit['label'] ?? null);

            return $label === null ? null : ['type' => 'marker', 'label' => $label];
        }

        return null;
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function number(mixed $value): ?float
    {
        return (is_int($value) || is_float($value) || is_string($value))
            && is_numeric($value)
            && is_finite((float) $value)
                ? (float) $value
                : null;
    }

    /** @param list<array<string, mixed>> $units */
    private static function estimateDuration(array $units): float
    {
        $duration = 0.0;
        foreach ($units as $unit) {
            if ($unit['type'] === 'pause') {
                $duration += (float) $unit['seconds'];
            } elseif ($unit['type'] === 'narration_L1') {
                $duration += count(preg_split('/\s+/', $unit['text']) ?: []) / 3 + 0.5;
            } elseif ($unit['type'] === 'L2') {
                $duration += ((mb_strlen($unit['text'], 'UTF-8') / 5) * 1.5)
                    / (float) ($unit['speed'] ?? 1) + 0.5;
            }
        }

        return $duration;
    }
}
