<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\ListAdminSentenceScriptTestsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminSentenceScriptTestsRequest;
use App\Http\Resources\Admin\AdminSentenceScriptTestSummaryResource;
use Illuminate\Http\JsonResponse;

final class ListAdminSentenceScriptTestsController extends Controller
{
    public function __invoke(
        ListAdminSentenceScriptTestsRequest $request,
        ListAdminSentenceScriptTestsAction $action,
    ): JsonResponse {
        $page = $action->handle($request->limit(), $request->cursor());

        return response()->json([
            'tests' => AdminSentenceScriptTestSummaryResource::collection($page['tests'])->resolve($request),
            'nextCursor' => $page['nextCursor'],
        ]);
    }
}
