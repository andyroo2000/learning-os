<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyNewCardQueueAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ListStudyNewCardQueueRequest;
use App\Http\Resources\Study\StudyNewCardQueueItemResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class ListStudyNewCardQueueController extends Controller
{
    public function __invoke(
        ListStudyNewCardQueueRequest $request,
        ListStudyNewCardQueueAction $listStudyNewCardQueue,
    ): JsonResponse {
        $userId = AuthenticatedUser::id($request);

        $page = $listStudyNewCardQueue->handle(
            userId: $userId,
            cursor: $request->cursor(),
            limit: $request->limit(),
            q: $request->q(),
        );

        return response()->json([
            'items' => StudyNewCardQueueItemResource::collection($page['items'])->resolve($request),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'nextCursor' => $page['nextCursor'],
        ]);
    }
}
