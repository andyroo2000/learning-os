<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\DeleteAdminScriptLabCoursesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteAdminScriptLabCoursesRequest;
use Illuminate\Http\JsonResponse;

final class DeleteAdminScriptLabCoursesController extends Controller
{
    public function __invoke(
        DeleteAdminScriptLabCoursesRequest $request,
        DeleteAdminScriptLabCoursesAction $action,
    ): JsonResponse {
        return response()->json(['deleted' => $action->handle($request->courseIds())]);
    }
}
