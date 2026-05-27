<?php

namespace App\Http\Controllers\Api\Reviews;

use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\StoreCardReviewEventRequest;
use App\Http\Resources\Reviews\CardReviewEventResource;
use Illuminate\Http\JsonResponse;

class StoreCardReviewEventController extends Controller
{
    public function __invoke(StoreCardReviewEventRequest $request, ReviewCardAction $reviewCard): JsonResponse
    {
        $data = $request->validated();

        $reviewEvent = $reviewCard->handle(ReviewCardData::fromInput(
            cardId: $data['card_id'],
            rating: $data['rating'],
            reviewedAt: $data['reviewed_at'],
            id: $data['id'] ?? null,
        ));

        return CardReviewEventResource::make($reviewEvent)
            ->response()
            ->setStatusCode(201);
    }
}
