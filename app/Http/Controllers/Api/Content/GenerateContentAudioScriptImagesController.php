<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\QueueContentAudioScriptGenerationAction;
use App\Domain\Content\Exceptions\ContentAudioScriptConflictException;
use App\Domain\Content\Exceptions\ContentAudioScriptQueueException;
use App\Domain\Content\Support\ContentAudioScriptJob;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\GenerateContentAudioScriptImagesRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class GenerateContentAudioScriptImagesController extends Controller
{
    public function __invoke(
        GenerateContentAudioScriptImagesRequest $request,
        QueueContentAudioScriptGenerationAction $queue,
        string $episodeId,
    ): JsonResponse {
        try {
            $job = $queue->handle(
                AuthenticatedUser::id($request),
                $request->convoLabUserId(),
                $episodeId,
                $request->generationData(),
            );
        } catch (ContentAudioScriptConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (ContentAudioScriptQueueException) {
            return response()->json(['message' => ContentAudioScriptJob::QUEUE_FAILED_MESSAGE], 503);
        }

        return response()->json([
            'jobId' => $job->id,
            'message' => $job->wasRecentlyCreated
                ? 'Script image generation started.'
                : 'Script image generation already in progress.',
            ...($job->wasRecentlyCreated ? [] : ['existing' => true]),
        ]);
    }
}
