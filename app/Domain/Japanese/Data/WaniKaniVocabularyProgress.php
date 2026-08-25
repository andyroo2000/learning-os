<?php

namespace App\Domain\Japanese\Data;

use Carbon\CarbonImmutable;

final readonly class WaniKaniVocabularyProgress
{
    /**
     * @param  list<string>  $readings
     * @param  list<string>  $meanings
     */
    public function __construct(
        public int $subjectId,
        public string $subjectType,
        public string $characters,
        public array $readings,
        public array $meanings,
        public int $srsStage,
        public ?CarbonImmutable $passedAt,
        public ?CarbonImmutable $burnedAt,
        public bool $hidden,
        public ?CarbonImmutable $assignmentUpdatedAt,
        public ?CarbonImmutable $subjectUpdatedAt,
        public ?CarbonImmutable $hiddenAt,
    ) {}
}
