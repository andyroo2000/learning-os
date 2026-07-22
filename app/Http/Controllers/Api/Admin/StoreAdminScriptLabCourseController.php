<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\CreateAdminScriptLabCourseAction;
use App\Domain\Admin\Data\CreateAdminScriptLabCourseData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminScriptLabCourseRequest;
use Illuminate\Http\JsonResponse;

final class StoreAdminScriptLabCourseController extends Controller
{
    public function __invoke(
        StoreAdminScriptLabCourseRequest $request,
        CreateAdminScriptLabCourseAction $action,
    ): JsonResponse {
        $course = $action->handle(
            $request->actorConvoLabUserId(),
            CreateAdminScriptLabCourseData::fromInput($request->validated()),
        );

        return response()->json([
            'courseId' => $course->id,
            'isTestCourse' => true,
        ]);
    }
}
