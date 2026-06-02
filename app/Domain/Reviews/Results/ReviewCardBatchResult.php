<?php

namespace App\Domain\Reviews\Results;

use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Support\Collection;

final readonly class ReviewCardBatchResult
{
    /**
     * @param  Collection<int, CardReviewEvent>  $reviewEvents
     */
    public function __construct(
        public Collection $reviewEvents,
        public bool $hasCreatedEvents,
    ) {}
}
