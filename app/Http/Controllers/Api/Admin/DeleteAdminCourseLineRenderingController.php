<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\DeleteAdminCourseLineRenderingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminWriteRequest;
use Illuminate\Http\JsonResponse;

final class DeleteAdminCourseLineRenderingController extends Controller
{
    public function __invoke(
        ConvoLabAdminWriteRequest $request,
        string $courseId,
        string $renderingId,
        DeleteAdminCourseLineRenderingAction $action,
    ): JsonResponse {
        $action->handle($courseId, $renderingId);

        return response()->json(['success' => true]);
    }
}
