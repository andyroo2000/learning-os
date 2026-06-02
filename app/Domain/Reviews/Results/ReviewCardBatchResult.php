<?php

namespace App\Domain\Reviews\Results;

use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Support\Collection;

final readonly class ReviewCardBatchResult
{
    /**
     * @param  Collection<int, CardReviewEvent>  $reviewEvents
     */
    private function __construct(
        public Collection $reviewEvents,
        public bool $hasCreatedEvents,
    ) {}

    /**
     * @param  Collection<int, CardReviewEvent>  $reviewEvents
     */
    public static function withCreatedEvents(Collection $reviewEvents): self
    {
        return new self($reviewEvents, true);
    }

    /**
     * @param  Collection<int, CardReviewEvent>  $reviewEvents
     */
    public static function withoutCreatedEvents(Collection $reviewEvents): self
    {
        return new self($reviewEvents, false);
    }
}
