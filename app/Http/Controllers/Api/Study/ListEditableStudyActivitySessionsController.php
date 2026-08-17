<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListEditableStudyActivitySessionsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ListEditableStudyActivitySessionsRequest;
use App\Http\Resources\Study\StudyActivitySessionResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class ListEditableStudyActivitySessionsController extends Controller
{
    public function __invoke(
        ListEditableStudyActivitySessionsRequest $request,
        ListEditableStudyActivitySessionsAction $list,
    ): JsonResponse {
        $pageSize = $request->pageSize();
        $page = $list->handle(AuthenticatedUser::id($request), $pageSize);

        return response()->json([
            'items' => StudyActivitySessionResource::collection($page->items())->resolve($request),
            'limit' => $pageSize->value(),
            'nextCursor' => $page->nextCursor()?->encode(),
        ]);
    }
}
