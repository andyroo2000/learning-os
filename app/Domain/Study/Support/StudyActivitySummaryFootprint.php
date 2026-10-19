<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Models\StudyActivitySession;
use Carbon\CarbonImmutable;

final readonly class StudyActivitySummaryFootprint
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $category,
        public int $durationMs,
    ) {}

    public static function fromSession(StudyActivitySession $session): self
    {
        return new self(
            CarbonImmutable::parse((string) $session->getRawOriginal('started_at'), 'UTC')->utc(),
            CarbonImmutable::parse((string) $session->getRawOriginal('ended_at'), 'UTC')->utc(),
            $session->category->value,
            (int) $session->duration_ms,
        );
    }

    public function matches(?self $other): bool
    {
        return $other !== null
            && $this->startsAt->equalTo($other->startsAt)
            && $this->endsAt->equalTo($other->endsAt)
            && $this->category === $other->category
            && $this->durationMs === $other->durationMs;
    }
}
