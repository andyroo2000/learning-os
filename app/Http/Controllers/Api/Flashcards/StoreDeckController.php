<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\CreateDeckAction;
use App\Domain\Flashcards\Data\CreateDeckData;
use App\Domain\Flashcards\Exceptions\DeckConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\StoreDeckRequest;
use App\Http\Resources\Flashcards\DeckResource;
use Illuminate\Http\JsonResponse;

class StoreDeckController extends Controller
{
    public function __invoke(StoreDeckRequest $request, CreateDeckAction $createDeck): JsonResponse
    {
        $data = $request->validated();
        $userId = $request->user()->id;

        try {
            $result = $createDeck->handle(CreateDeckData::fromInput(
                userId: $userId,
                name: $data['name'],
                description: $data['description'] ?? null,
                id: $data['id'] ?? null,
            ));
        } catch (DeckConflictException $exception) {
            // Hide cross-user IDs before returning same-user deletion details.
            if ($exception->shouldBeHiddenFrom($userId)) {
                return response()->json(['message' => 'Not Found'], 404);
            }

            // From here down, the conflict is owned by the requesting user and can expose a reason.
            if ($exception->shouldBeGoneFor($userId)) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'reason' => $exception->reason(),
                ], 410);
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], 409);
        }

        return DeckResource::make($result->deck)
            ->response()
            ->setStatusCode($result->wasCreated ? 201 : 200);
    }
}
