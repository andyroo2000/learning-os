<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\GenerateAdminSentenceScriptAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GenerateAdminSentenceScriptRequest;
use Illuminate\Http\JsonResponse;

final class GenerateAdminSentenceScriptController extends Controller
{
    public function __invoke(
        GenerateAdminSentenceScriptRequest $request,
        GenerateAdminSentenceScriptAction $action,
    ): JsonResponse {
        $generated = $action->handle(
            $request->actorConvoLabUserId(),
            $request->generationData(),
        );
        $result = $generated['result'];
        $payload = [
            'units' => $result->units,
            'estimatedDurationSeconds' => $result->estimatedDurationSeconds,
            'rawResponse' => $result->rawResponse,
            'resolvedPrompt' => $result->resolvedPrompt,
            'translation' => $result->translation,
            'testId' => $generated['test']->id,
        ];
        if ($result->parseError !== null) {
            $payload['parseError'] = $result->parseError;
        }

        return response()->json($payload);
    }
}
