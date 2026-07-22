<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\ShowAdminSentenceScriptTestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminActorReadRequest;
use App\Http\Resources\Admin\AdminSentenceScriptTestResource;
use Illuminate\Http\JsonResponse;

final class ShowAdminSentenceScriptTestController extends Controller
{
    public function __invoke(
        ConvoLabAdminActorReadRequest $request,
        string $testId,
        ShowAdminSentenceScriptTestAction $action,
    ): JsonResponse {
        $resource = new AdminSentenceScriptTestResource($action->handle(
            $request->actorConvoLabUserId(),
            $testId,
        ));

        return response()->json($resource->resolve($request));
    }
}
