<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Flashcards\Actions\ReorderNewCardQueueAction;
use App\Domain\Flashcards\Exceptions\CardValidationException;
use App\Domain\Study\Actions\ListStudyNewCardQueueAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ReorderStudyNewCardQueueRequest;
use App\Http\Resources\Study\StudyNewCardQueueItemResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ReorderStudyNewCardQueueController extends Controller
{
    public function __invoke(
        ReorderStudyNewCardQueueRequest $request,
        ReorderNewCardQueueAction $reorderNewCardQueue,
        ListStudyNewCardQueueAction $listStudyNewCardQueue,
    ): JsonResponse {
        $data = $request->validated();
        $userId = AuthenticatedUser::id($request);

        try {
            $reorderNewCardQueue->handle(
                userId: $userId,
                cardIds: $data['cardIds'],
            );
        } catch (CardValidationException $exception) {
            throw ValidationException::withMessages([
                'cardIds' => [$exception->getMessage()],
            ]);
        }

        $page = $listStudyNewCardQueue->handle(
            userId: $userId,
        );

        return response()->json([
            'items' => StudyNewCardQueueItemResource::collection($page['items'])->resolve($request),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'nextCursor' => $page['nextCursor'],
        ]);
    }
}
