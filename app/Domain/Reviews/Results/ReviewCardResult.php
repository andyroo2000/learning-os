<?php

namespace App\Domain\Reviews\Results;

use App\Domain\Reviews\Models\CardReviewEvent;

final readonly class ReviewCardResult
{
    private function __construct(
        public CardReviewEvent $reviewEvent,
        public bool $created,
    ) {}

    public static function created(CardReviewEvent $reviewEvent): self
    {
        return new self($reviewEvent, true);
    }

    public static function existing(CardReviewEvent $reviewEvent): self
    {
        return new self($reviewEvent, false);
    }
}
