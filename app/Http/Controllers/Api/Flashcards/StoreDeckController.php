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
            $deck = $createDeck->handle(CreateDeckData::fromInput(
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

            if ($exception->shouldBeGoneFor($userId)) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 410);
            }

            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        // Existing models returned for idempotent retries keep wasRecentlyCreated=false,
        // which lets HTTP distinguish replayed creates from fresh inserts.
        return DeckResource::make($deck)
            ->response()
            ->setStatusCode($deck->wasRecentlyCreated ? 201 : 200);
    }
}
