<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\ShowContentAudioScriptGenerationJobAction;
use App\Domain\Content\Support\ContentAudioScriptJob;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ShowContentAudioScriptGenerationJobRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class ShowContentAudioScriptGenerationJobController extends Controller
{
    public function __invoke(
        ShowContentAudioScriptGenerationJobRequest $request,
        ShowContentAudioScriptGenerationJobAction $show,
        string $jobId,
    ): JsonResponse {
        $job = $show->handle(AuthenticatedUser::id($request), $request->convoLabUserId(), $jobId);
        if ($job === null) {
            return response()->json(['message' => 'Script audio job not found.'], 404);
        }

        return response()->json([
            'id' => $job->id,
            'state' => $job->state,
            'progress' => $job->progress,
            'result' => $job->state === ContentAudioScriptJob::STATE_COMPLETED ? $job->result : null,
        ]);
    }
}
