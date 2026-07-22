<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\ShowAdminScriptLabCourseAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminReadRequest;
use App\Http\Resources\Admin\AdminScriptLabCourseResource;
use Illuminate\Http\JsonResponse;

final class ShowAdminScriptLabCourseController extends Controller
{
    public function __invoke(
        ConvoLabAdminReadRequest $request,
        string $courseId,
        ShowAdminScriptLabCourseAction $action,
    ): JsonResponse {
        return response()->json(
            AdminScriptLabCourseResource::make($action->handle($courseId))->resolve($request),
        );
    }
}
