<?php

namespace App\Domain\Admin\Results;

use App\Domain\Content\Results\ContentCourseScriptUnit;
use InvalidArgumentException;
use JsonException;

final readonly class AdminCourseScriptResult
{
    private const MAX_RESPONSE_BYTES = 1_000_000;

    private const MAX_SCRIPT_UNITS = 1_000;

    /** @param list<ContentCourseScriptUnit> $units */
    private function __construct(
        public array $units,
        public int $estimatedDurationSeconds,
    ) {}

    /** @param list<string> $speakerVoiceIds */
    public static function fromJson(
        string $json,
        string $narratorVoiceId,
        array $speakerVoiceIds,
        string $targetLanguage,
        int $maximumDurationSeconds,
    ): self {
        if (strlen($json) > self::MAX_RESPONSE_BYTES) {
            throw new InvalidArgumentException('Script provider response is too large.');
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Script provider response must be valid JSON.', 0, $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded) || array_keys($decoded) !== ['scriptUnits']) {
            throw new InvalidArgumentException('Script provider response shape is invalid.');
        }
        $rawUnits = $decoded['scriptUnits'];
        if (! is_array($rawUnits) || ! array_is_list($rawUnits) || $rawUnits === []
            || count($rawUnits) > self::MAX_SCRIPT_UNITS) {
            throw new InvalidArgumentException('Script provider units are invalid.');
        }

        $units = array_map(static function (mixed $rawUnit): ContentCourseScriptUnit {
            if (! is_array($rawUnit) || array_is_list($rawUnit)) {
                throw new InvalidArgumentException('Script provider unit must be an object.');
            }

            return ContentCourseScriptUnit::fromProvider($rawUnit);
        }, $rawUnits);

        foreach ($units as $unit) {
            if ($unit->type === 'narration_L1' && $unit->voiceId !== $narratorVoiceId) {
                throw new InvalidArgumentException('Script provider returned an unknown narrator voice.');
            }
            if ($unit->type === 'L2' && ! in_array($unit->voiceId, $speakerVoiceIds, true)) {
                throw new InvalidArgumentException('Script provider returned an unknown speaker voice.');
            }
            if ($targetLanguage === 'ja' && $unit->type === 'L2' && $unit->reading === null) {
                throw new InvalidArgumentException('Script provider omitted a Japanese reading.');
            }
        }

        $estimatedDurationSeconds = max(1, (int) round(array_sum(array_map(
            static fn (ContentCourseScriptUnit $unit): float => $unit->estimatedDurationSeconds(),
            $units,
        ))));
        if ($estimatedDurationSeconds > $maximumDurationSeconds) {
            throw new InvalidArgumentException('Script provider exceeded the maximum lesson duration.');
        }

        return new self($units, $estimatedDurationSeconds);
    }

    /** @return list<array<string, float|string>> */
    public function payload(): array
    {
        return array_map(
            static fn (ContentCourseScriptUnit $unit): array => $unit->toArray(),
            $this->units,
        );
    }
}
