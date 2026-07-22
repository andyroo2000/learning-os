<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\TestAdminPronunciationAction;
use App\Domain\Admin\Support\AdminScriptLabAudio;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TestAdminPronunciationRequest;
use Illuminate\Http\JsonResponse;

final class TestAdminPronunciationController extends Controller
{
    public function __invoke(
        TestAdminPronunciationRequest $request,
        TestAdminPronunciationAction $action,
    ): JsonResponse {
        $rendering = $action->handle(
            $request->actorConvoLabUserId(),
            $request->pronunciationData(),
        );

        return response()->json([
            'preprocessedText' => $rendering->synthesized_text,
            'audioUrl' => AdminScriptLabAudio::audioUrl($rendering->id),
            'durationSeconds' => $rendering->duration_seconds,
            'format' => $rendering->format,
            'originalText' => $rendering->original_text,
        ]);
    }
}
