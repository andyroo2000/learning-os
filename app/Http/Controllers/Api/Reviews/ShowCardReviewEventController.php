<?php

namespace App\Http\Controllers\Api\Reviews;

use App\Domain\Reviews\Models\CardReviewEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Reviews\CardReviewEventResource;

class ShowCardReviewEventController extends Controller
{
    public function __invoke(CardReviewEvent $cardReviewEvent): CardReviewEventResource
    {
        $this->authorize('view', $cardReviewEvent);

        $cardReviewEvent->load([
            'card' => fn ($query) => $query->withTrashed(),
            'card.deck' => fn ($query) => $query->withTrashed(),
        ]);

        return CardReviewEventResource::make($cardReviewEvent);
    }
}
