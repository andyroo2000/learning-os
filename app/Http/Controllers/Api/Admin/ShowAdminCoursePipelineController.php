<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\ShowAdminCoursePipelineAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminReadRequest;
use App\Http\Resources\Admin\AdminCoursePipelineResource;
use Illuminate\Http\JsonResponse;

final class ShowAdminCoursePipelineController extends Controller
{
    public function __invoke(
        ConvoLabAdminReadRequest $request,
        string $courseId,
        ShowAdminCoursePipelineAction $action,
    ): JsonResponse {
        return response()->json(
            AdminCoursePipelineResource::make($action->handle($courseId))->resolve($request),
        );
    }
}
