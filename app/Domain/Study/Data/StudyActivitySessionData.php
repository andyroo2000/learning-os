<?php

namespace App\Domain\Study\Data;

use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Enums\StudyActivityOrigin;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Support\StudyActivitySessionId;
use Carbon\CarbonImmutable;

final readonly class StudyActivitySessionData
{
    public function __construct(
        public string $clientSessionId,
        public StudyActivityCategory $category,
        public StudyActivityKind $activity,
        public StudyActivitySource $source,
        public ?string $name,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $endedAt,
        public int $durationMs,
        public ?int $audioPlaybackMs,
        public ?int $cardsCreated,
        public StudyActivityOrigin $origin = StudyActivityOrigin::Legacy,
    ) {}

    /** @param array<string, mixed> $session */
    public static function fromValidated(array $session): self
    {
        return new self(
            clientSessionId: StudyActivitySessionId::normalize($session['clientSessionId']),
            category: StudyActivityCategory::from($session['category']),
            activity: StudyActivityKind::from($session['activity']),
            source: StudyActivitySource::from($session['source']),
            name: $session['name'] ?? null,
            startedAt: CarbonImmutable::parse($session['startedAt']),
            endedAt: CarbonImmutable::parse($session['endedAt']),
            durationMs: $session['durationMs'],
            audioPlaybackMs: $session['audioPlaybackMs'] ?? null,
            cardsCreated: $session['cardsCreated'] ?? null,
            origin: isset($session['origin'])
                ? StudyActivityOrigin::from($session['origin'])
                : StudyActivityOrigin::Legacy,
        );
    }
}
