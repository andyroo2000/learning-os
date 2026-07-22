<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\GenerateAdminCourseDialogueAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GenerateAdminCourseDialogueRequest;
use Illuminate\Http\JsonResponse;

final class GenerateAdminCourseDialogueController extends Controller
{
    public function __invoke(
        GenerateAdminCourseDialogueRequest $request,
        string $courseId,
        GenerateAdminCourseDialogueAction $action,
    ): JsonResponse {
        return response()->json([
            'exchanges' => $action->handle($courseId, $request->dialogueData()),
        ]);
    }
}
