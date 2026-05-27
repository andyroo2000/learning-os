<?php

namespace App\Http\Controllers\Api\Reviews;

use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\StoreCardReviewEventBatchRequest;
use App\Http\Resources\Reviews\CardReviewEventResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class StoreCardReviewEventBatchController extends Controller
{
    public function __invoke(StoreCardReviewEventBatchRequest $request, ReviewCardBatchAction $reviewCards): JsonResponse
    {
        try {
            $reviewEvents = $reviewCards->handle(
                collect($request->validated('events'))
                    ->map(fn (array $event) => ReviewCardData::fromInput(
                        cardId: $event['card_id'],
                        rating: $event['rating'],
                        reviewedAt: $event['reviewed_at'],
                        id: $event['id'] ?? null,
                        clientEventId: $event['client_event_id'],
                        deviceId: $event['device_id'],
                        clientCreatedAt: $event['client_created_at'],
                    )),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'events' => [$exception->getMessage()],
            ]);
        }

        return CardReviewEventResource::collection($reviewEvents)
            ->response()
            ->setStatusCode(201);
    }
}
