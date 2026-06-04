<?php

namespace App\Http\Controllers\Api\Reviews;

use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
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
        $userId = (int) $request->user()->id;

        try {
            $result = $reviewCards->handle(
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
        } catch (CardReviewEventConflictException $exception) {
            if ($exception->shouldBeHiddenFrom($userId)) {
                return response()->json(['message' => 'Not Found'], 404);
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], 409);
        }

        return CardReviewEventResource::collection($result->reviewEvents)
            ->response()
            ->setStatusCode($result->hasCreatedEvents ? 201 : 200);
    }
}
