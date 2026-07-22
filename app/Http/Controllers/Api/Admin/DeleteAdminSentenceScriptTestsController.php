<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\DeleteAdminSentenceScriptTestsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteAdminSentenceScriptTestsRequest;
use Illuminate\Http\JsonResponse;

final class DeleteAdminSentenceScriptTestsController extends Controller
{
    public function __invoke(
        DeleteAdminSentenceScriptTestsRequest $request,
        DeleteAdminSentenceScriptTestsAction $action,
    ): JsonResponse {
        return response()->json(['deleted' => $action->handle(
            $request->actorConvoLabUserId(),
            $request->testIds(),
        )]);
    }
}
