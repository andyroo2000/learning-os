<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\ShowContentAudioGenerationJobAction;
use App\Domain\Content\Support\ContentAudioGeneration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ShowContentAudioGenerationJobRequest;
use Illuminate\Http\JsonResponse;

final class ShowContentAudioGenerationJobController extends Controller
{
    public function __invoke(
        ShowContentAudioGenerationJobRequest $request,
        ShowContentAudioGenerationJobAction $show,
        string $jobId,
    ): JsonResponse {
        $job = $show->handle($request->contentUserId(), $request->convoLabUserId(), $jobId);
        if ($job === null) {
            return response()->json(['message' => 'Audio generation job not found'], 404);
        }

        return response()->json([
            'id' => $job->id,
            'state' => $job->state,
            'progress' => $job->progress,
            'result' => $job->state === ContentAudioGeneration::STATE_COMPLETED ? $job->result : null,
        ]);
    }
}
