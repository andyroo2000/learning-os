<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\SynthesizeAdminScriptLabLineAction;
use App\Domain\Admin\Support\AdminScriptLabAudio;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SynthesizeAdminScriptLabLineRequest;
use Illuminate\Http\JsonResponse;

final class SynthesizeAdminScriptLabLineController extends Controller
{
    public function __invoke(
        SynthesizeAdminScriptLabLineRequest $request,
        SynthesizeAdminScriptLabLineAction $action,
    ): JsonResponse {
        $rendering = $action->handle(
            $request->actorConvoLabUserId(),
            $request->synthesisData(),
        );

        return response()->json([
            'audioUrl' => AdminScriptLabAudio::audioUrl($rendering->id),
        ]);
    }
}
