<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyLearningItemsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ListStudyLearningItemsRequest;
use App\Http\Resources\Study\StudyLearningItemResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class ListStudyLearningItemsController extends Controller
{
    public function __invoke(
        ListStudyLearningItemsRequest $request,
        ListStudyLearningItemsAction $listLearningItems,
    ): JsonResponse {
        $pageSize = $request->pageSize();
        $page = $listLearningItems->handle(
            userId: AuthenticatedUser::id($request),
            pageSize: $pageSize,
            q: $request->searchQuery(),
        );

        return response()->json([
            'items' => StudyLearningItemResource::collection($page['items'])->resolve($request),
            'limit' => $pageSize->value(),
            'nextCursor' => $page['nextCursor']?->encode(),
        ]);
    }
}
