<?php

namespace App\Http\Controllers\Api\Reviews;

use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\StoreCardReviewEventRequest;
use App\Http\Resources\Reviews\CardReviewEventResource;
use Illuminate\Http\JsonResponse;

class StoreCardReviewEventController extends Controller
{
    public function __invoke(StoreCardReviewEventRequest $request, ReviewCardAction $reviewCard): JsonResponse
    {
        $data = $request->validated();
        $userId = (int) $request->user()->id;

        try {
            $result = $reviewCard->handle(ReviewCardData::fromInput(
                cardId: $data['card_id'],
                rating: $data['rating'],
                reviewedAt: $data['reviewed_at'],
                id: $data['id'] ?? null,
                clientEventId: $data['client_event_id'] ?? null,
                deviceId: $data['device_id'] ?? null,
                clientCreatedAt: $data['client_created_at'] ?? null,
            ));
        } catch (CardReviewEventConflictException $exception) {
            if ($exception->shouldBeHiddenFrom($userId)) {
                return response()->json(['message' => 'Not Found'], 404);
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], 409);
        }

        return CardReviewEventResource::make($result->reviewEvent)
            ->response()
            ->setStatusCode($result->wasCreated ? 201 : 200);
    }
}
