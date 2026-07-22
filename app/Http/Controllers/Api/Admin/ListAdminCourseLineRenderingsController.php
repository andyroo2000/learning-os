<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\ListAdminCourseLineRenderingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminReadRequest;
use App\Http\Resources\Admin\AdminCourseLineRenderingResource;
use Illuminate\Http\JsonResponse;

final class ListAdminCourseLineRenderingsController extends Controller
{
    public function __invoke(
        ConvoLabAdminReadRequest $request,
        string $courseId,
        ListAdminCourseLineRenderingsAction $action,
    ): JsonResponse {
        return response()->json([
            'renderings' => AdminCourseLineRenderingResource::collection(
                $action->handle($courseId),
            )->resolve($request),
        ]);
    }
}
