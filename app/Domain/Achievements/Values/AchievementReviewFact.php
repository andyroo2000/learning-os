<?php

namespace App\Domain\Achievements\Values;

use App\Domain\Reviews\Enums\CardReviewRating;
use Carbon\CarbonImmutable;
use stdClass;

/** Immutable history facts avoid repeated Eloquent hydration and date/JSON casts. */
final readonly class AchievementReviewFact
{
    public string $id;

    public string $cardId;

    public CardReviewRating $rating;

    public CarbonImmutable $reviewedAt;

    public CarbonImmutable $createdAt;

    public float $stability;

    public function __construct(stdClass $row)
    {
        $state = json_decode($row->scheduler_state_after ?? 'null', true, flags: JSON_THROW_ON_ERROR);
        $stability = $state['stability'] ?? 0;

        $this->id = (string) $row->id;
        $this->cardId = (string) $row->card_id;
        $this->rating = CardReviewRating::from($row->rating);
        $this->reviewedAt = CarbonImmutable::parse($row->reviewed_at);
        $this->createdAt = CarbonImmutable::parse($row->created_at);
        $this->stability = is_int($stability) || is_float($stability) ? (float) $stability : 0.0;
    }
}
