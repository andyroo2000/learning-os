<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\ShowContentDialogueGenerationJobAction;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ShowContentDialogueGenerationJobRequest;
use App\Http\Resources\Content\ContentDialogueGenerationResultResource;
use Illuminate\Http\JsonResponse;

final class ShowContentDialogueGenerationJobController extends Controller
{
    public function __invoke(
        ShowContentDialogueGenerationJobRequest $request,
        ShowContentDialogueGenerationJobAction $show,
        string $jobId,
    ): JsonResponse {
        $job = $show->handle(
            $request->contentUserId(),
            $request->convoLabUserId(),
            $jobId,
        );
        if ($job === null) {
            return response()->json(['message' => 'Dialogue generation job not found'], 404);
        }

        $dialogue = $job->episode?->dialogue;
        $result = $job->state === ContentDialogueGeneration::STATE_COMPLETED && $dialogue !== null
            ? (new ContentDialogueGenerationResultResource($dialogue))->resolve($request)
            : null;

        return response()->json([
            'id' => $job->id,
            'state' => $job->state,
            'progress' => $job->progress,
            'result' => $result,
        ]);
    }
}
