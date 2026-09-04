<?php

namespace App\Domain\Admin\Results;

use App\Domain\Content\Data\ContentCourseScriptUnits;
use App\Domain\Content\Results\ContentCourseScriptUnit;
use InvalidArgumentException;
use JsonException;

final readonly class AdminCourseScriptResultParser
{
    private const MAX_RESPONSE_BYTES = 1_000_000;

    public function __construct(
        private string $json,
        private AdminCourseScriptConstraints $constraints,
    ) {}

    public function parse(): AdminCourseScriptParsedResult
    {
        $units = $this->unitsFromJson();
        $this->assertUnitContracts($units);

        return new AdminCourseScriptParsedResult($units, $this->estimatedDuration($units));
    }

    /** @return list<ContentCourseScriptUnit> */
    private function unitsFromJson(): array
    {
        $decoded = $this->decode();
        if (! $this->hasExpectedResponseShape($decoded)) {
            throw new InvalidArgumentException('Script provider response shape is invalid.');
        }

        return $this->parseUnits($decoded['scriptUnits']);
    }

    private function decode(): mixed
    {
        if (strlen($this->json) > self::MAX_RESPONSE_BYTES) {
            throw new InvalidArgumentException('Script provider response is too large.');
        }

        try {
            return json_decode($this->json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Script provider response must be valid JSON.', 0, $exception);
        }
    }

    private function hasExpectedResponseShape(mixed $decoded): bool
    {
        return is_array($decoded)
            && ! array_is_list($decoded)
            && array_keys($decoded) === ['scriptUnits'];
    }

    /** @return list<ContentCourseScriptUnit> */
    private function parseUnits(mixed $rawUnits): array
    {
        if (! $this->isValidUnitList($rawUnits)) {
            throw new InvalidArgumentException('Script provider units are invalid.');
        }

        return array_map($this->parseUnit(...), $rawUnits);
    }

    private function isValidUnitList(mixed $rawUnits): bool
    {
        return is_array($rawUnits)
            && array_is_list($rawUnits)
            && $rawUnits !== []
            && count($rawUnits) <= ContentCourseScriptUnits::MAX_UNITS;
    }

    private function parseUnit(mixed $rawUnit): ContentCourseScriptUnit
    {
        if (! is_array($rawUnit) || array_is_list($rawUnit)) {
            throw new InvalidArgumentException('Script provider unit must be an object.');
        }

        return ContentCourseScriptUnit::fromProvider($rawUnit);
    }

    /** @param list<ContentCourseScriptUnit> $units */
    private function assertUnitContracts(array $units): void
    {
        foreach ($units as $unit) {
            $this->assertNarratorVoice($unit);
            $this->assertSpeakerVoice($unit);
            $this->assertJapaneseReading($unit);
        }
    }

    private function assertNarratorVoice(ContentCourseScriptUnit $unit): void
    {
        if ($unit->type === 'narration_L1' && $unit->voiceId !== $this->constraints->narratorVoiceId) {
            throw new InvalidArgumentException('Script provider returned an unknown narrator voice.');
        }
    }

    private function assertSpeakerVoice(ContentCourseScriptUnit $unit): void
    {
        if ($unit->type === 'L2' && ! in_array($unit->voiceId, $this->constraints->speakerVoiceIds, true)) {
            throw new InvalidArgumentException('Script provider returned an unknown speaker voice.');
        }
    }

    private function assertJapaneseReading(ContentCourseScriptUnit $unit): void
    {
        if (! $this->hasRequiredJapaneseReading($unit)) {
            throw new InvalidArgumentException('Script provider omitted a Japanese reading.');
        }
    }

    private function hasRequiredJapaneseReading(ContentCourseScriptUnit $unit): bool
    {
        return $this->constraints->targetLanguage !== 'ja'
            || $unit->type !== 'L2'
            || $unit->reading !== null;
    }

    /** @param list<ContentCourseScriptUnit> $units */
    private function estimatedDuration(array $units): int
    {
        $estimatedDurationSeconds = max(1, (int) round(array_sum(array_map(
            static fn (ContentCourseScriptUnit $unit): float => $unit->estimatedDurationSeconds(),
            $units,
        ))));
        if ($estimatedDurationSeconds > $this->constraints->maximumDurationSeconds) {
            throw new InvalidArgumentException('Script provider exceeded the maximum lesson duration.');
        }

        return $estimatedDurationSeconds;
    }
}
