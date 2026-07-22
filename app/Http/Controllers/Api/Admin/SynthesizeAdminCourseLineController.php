<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\SynthesizeAdminCourseLineAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SynthesizeAdminCourseLineRequest;
use Illuminate\Http\JsonResponse;

final class SynthesizeAdminCourseLineController extends Controller
{
    public function __invoke(
        SynthesizeAdminCourseLineRequest $request,
        string $courseId,
        SynthesizeAdminCourseLineAction $action,
    ): JsonResponse {
        $rendering = $action->handle($courseId, $request->synthesisData());

        return response()->json([
            'audioUrl' => $rendering->audio_url,
            'renderingId' => $rendering->id,
        ]);
    }
}
