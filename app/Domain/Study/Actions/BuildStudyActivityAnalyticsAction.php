<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Data\StudyActivityAnalyticsOptions;
use App\Domain\Study\Support\StudyActivityAnalyticsRangeFactory;
use App\Domain\Study\Support\StudyActivityAnalyticsReader;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class BuildStudyActivityAnalyticsAction
{
    public function __construct(
        private readonly StudyActivityAnalyticsRangeFactory $factory,
        private readonly StudyActivityAnalyticsReader $reader,
    ) {}

    /** @return array{generatedAt: string, anchorDate: string, timezone: string, ranges: list<array<string, mixed>>} */
    public function handle(int $userId, DateTimeZone $timezone, int $weekStartsOn, ?StudyActivityAnalyticsOptions $options = null): array
    {
        if ($weekStartsOn < 1 || $weekStartsOn > 7) {
            throw new InvalidArgumentException('The first weekday must be between 1 and 7.');
        }
        $options ??= new StudyActivityAnalyticsOptions;
        $now = ($options->now ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
        $anchor = ($options->anchor ?? $now)->setTimezone($timezone);
        $resolved = new StudyActivityAnalyticsOptions($now, $anchor, $options->adaptiveAllTime, $options->ranges);

        return DB::transaction(function () use ($userId, $timezone, $weekStartsOn, $resolved, $now, $anchor): array {
            // The same owner lock protects mutations, earliest-history metadata, and summary fills.
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $ranges = $this->factory->make($userId, $timezone, $weekStartsOn, $resolved);

            return [
                'generatedAt' => $now->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'anchorDate' => $anchor->toDateString(),
                'timezone' => $timezone->getName(),
                'ranges' => array_values($this->reader->read($userId, $ranges, $now)),
            ];
        });
    }
}
