<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyNewCardQueueAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ListStudyNewCardQueueRequest;
use App\Http\Resources\Study\StudyNewCardQueueItemResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ListStudyNewCardQueueController extends Controller
{
    public function __invoke(
        ListStudyNewCardQueueRequest $request,
        ListStudyNewCardQueueAction $listStudyNewCardQueue,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $page = $listStudyNewCardQueue->handle(
            userId: $user->id,
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
