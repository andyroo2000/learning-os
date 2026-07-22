<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\BuildAdminCourseScriptConfigAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminReadRequest;
use Illuminate\Http\JsonResponse;

final class BuildAdminCourseScriptConfigController extends Controller
{
    public function __invoke(
        ConvoLabAdminReadRequest $request,
        string $courseId,
        BuildAdminCourseScriptConfigAction $action,
    ): JsonResponse {
        return response()->json(['config' => $action->handle($courseId)]);
    }
}
