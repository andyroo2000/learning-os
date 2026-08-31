<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Flashcards\Actions\PromoteNewCardToFrontAction;
use App\Domain\Study\Actions\ListStudyNewCardQueueAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyNewCardQueueItemResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromoteStudyNewCardQueueController extends Controller
{
    public function __invoke(
        Request $request,
        string $cardId,
        PromoteNewCardToFrontAction $promoteNewCardToFront,
        ListStudyNewCardQueueAction $listStudyNewCardQueue,
    ): JsonResponse {
        $userId = AuthenticatedUser::id($request);

        $promoteNewCardToFront->handle(
            userId: $userId,
            cardId: $cardId,
        );

        $page = $listStudyNewCardQueue->handle(userId: $userId);

        return response()->json([
            'items' => StudyNewCardQueueItemResource::collection($page['items'])->resolve($request),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'nextCursor' => $page['nextCursor'],
        ]);
    }
}
