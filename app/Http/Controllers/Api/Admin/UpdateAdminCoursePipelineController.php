<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\UpdateAdminCoursePipelineAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminCoursePipelineRequest;
use Illuminate\Http\JsonResponse;

final class UpdateAdminCoursePipelineController extends Controller
{
    public function __invoke(
        UpdateAdminCoursePipelineRequest $request,
        string $courseId,
        UpdateAdminCoursePipelineAction $action,
    ): JsonResponse {
        $action->handle($courseId, $request->pipelineData());

        return response()->json(['success' => true]);
    }
}
