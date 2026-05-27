<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReviewCardAction
{
    public function handle(ReviewCardData $data): CardReviewEvent
    {
        if (! Str::isUlid($data->cardId)) {
            throw new InvalidArgumentException('Card ID must be a valid ULID.');
        }

        if (! Card::query()->whereKey($data->cardId)->exists()) {
            throw new InvalidArgumentException('Card does not exist.');
        }

        if ($data->rating === '') {
            throw new InvalidArgumentException('Review rating is required.');
        }

        $rating = CardReviewRating::tryFrom($data->rating);

        if ($rating === null) {
            throw new InvalidArgumentException('Review rating must be one of: '.implode(', ', CardReviewRating::values()).'.');
        }

        if ($data->id !== null && ! Str::isUlid($data->id)) {
            throw new InvalidArgumentException('Review event ID must be a valid ULID.');
        }

        $reviewEvent = new CardReviewEvent([
            'card_id' => $data->cardId,
            'rating' => $rating,
            'reviewed_at' => $data->reviewedAt,
        ]);

        if ($data->id !== null) {
            $reviewEvent->id = $data->id;
        }

        $reviewEvent->save();

        return $reviewEvent;
    }
}
