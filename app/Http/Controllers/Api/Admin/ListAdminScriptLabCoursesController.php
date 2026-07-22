<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\ListAdminScriptLabCoursesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminReadRequest;
use App\Http\Resources\Admin\AdminScriptLabCourseSummaryResource;
use Illuminate\Http\JsonResponse;

final class ListAdminScriptLabCoursesController extends Controller
{
    public function __invoke(
        ConvoLabAdminReadRequest $request,
        ListAdminScriptLabCoursesAction $action,
    ): JsonResponse {
        return response()->json([
            'courses' => AdminScriptLabCourseSummaryResource::collection($action->handle())->resolve($request),
        ]);
    }
}
