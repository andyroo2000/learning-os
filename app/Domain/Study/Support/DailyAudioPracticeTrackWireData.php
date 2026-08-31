<?php

namespace App\Domain\Study\Support;

final class DailyAudioPracticeTrackWireData
{
    private function __construct() {}

    /**
     * @param  array<int, mixed>|null  $units
     * @return array<int, mixed>|null
     */
    public static function scriptUnits(?array $units): ?array
    {
        if ($units === null) {
            return null;
        }

        return array_map(static function (mixed $unit): mixed {
            if (! is_array($unit)) {
                return $unit;
            }

            $type = $unit['type'] ?? match ($unit['kind'] ?? null) {
                'target_language' => 'L2',
                'native_language' => 'narration_L1',
                default => $unit['kind'] ?? null,
            };
            if (! is_string($type)) {
                return $unit;
            }

            unset($unit['kind']);
            $unit = ['type' => $type, ...$unit];
            if (in_array($type, ['L2', 'narration_L1'], true)
                && ! array_key_exists('voiceId', $unit)) {
                $unit['voiceId'] = '';
            }

            return $unit;
        }, array_values($units));
    }

    /**
     * @param  array<int, mixed>|null  $timings
     * @param  array<int, mixed>|null  $units  Canonical script units from scriptUnits().
     * @return array<int, mixed>|null
     */
    public static function timingData(?array $timings, ?array $units): ?array
    {
        if ($timings === null) {
            return null;
        }

        $timedUnitIndexes = [];
        foreach ($units ?? [] as $unitIndex => $unit) {
            if (is_array($unit) && ($unit['type'] ?? null) !== 'marker') {
                $timedUnitIndexes[] = $unitIndex;
            }
        }

        $normalized = [];
        foreach (array_values($timings) as $timingIndex => $timing) {
            if (! is_array($timing)) {
                $normalized[] = $timing;

                continue;
            }

            $unitIndex = $timing['unitIndex'] ?? $timedUnitIndexes[$timingIndex] ?? null;
            if ($unitIndex === null) {
                // A legacy timing cannot be associated safely without its script.
                return null;
            }

            $startTime = $timing['startTime'] ?? $timing['startMs'] ?? null;
            $endTime = $timing['endTime'] ?? $timing['endMs'] ?? null;
            unset($timing['startMs'], $timing['endMs']);
            $normalized[] = [
                'unitIndex' => $unitIndex,
                'startTime' => $startTime,
                'endTime' => $endTime,
                ...$timing,
            ];
        }

        return $normalized;
    }
}
