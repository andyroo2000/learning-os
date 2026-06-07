<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyCardDraftsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ListStudyCardDraftsRequest;
use App\Http\Resources\Study\StudyCardDraftResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class ListStudyCardDraftsController extends Controller
{
    public function __invoke(
        ListStudyCardDraftsRequest $request,
        ListStudyCardDraftsAction $listStudyCardDrafts,
    ): JsonResponse {
        $userId = AuthenticatedUser::id($request);

        $result = $listStudyCardDrafts->handle(
            userId: $userId,
            cursor: $request->cursor(),
            limit: $request->limit(),
        );

        return response()->json([
            'drafts' => StudyCardDraftResource::collection($result['drafts']),
            'total' => $result['total'],
            'limit' => $result['limit'],
            'nextCursor' => $result['nextCursor'],
        ]);
    }
}
