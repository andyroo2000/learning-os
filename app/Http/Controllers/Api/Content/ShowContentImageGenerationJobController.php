<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\ShowContentImageGenerationJobAction;
use App\Domain\Content\Support\ContentImageGeneration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ShowContentImageGenerationJobRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class ShowContentImageGenerationJobController extends Controller
{
    public function __invoke(
        ShowContentImageGenerationJobRequest $request,
        ShowContentImageGenerationJobAction $show,
        string $jobId,
    ): JsonResponse {
        $job = $show->handle(AuthenticatedUser::id($request), $request->convoLabUserId(), $jobId);
        if ($job === null) {
            return response()->json(['message' => 'Job not found'], 404);
        }

        return response()->json([
            'id' => $job->id,
            'state' => $job->state,
            'progress' => $job->progress,
            'result' => $job->state === ContentImageGeneration::STATE_COMPLETED ? $job->result : null,
        ]);
    }
}
