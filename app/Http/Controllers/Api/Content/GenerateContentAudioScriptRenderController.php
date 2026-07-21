<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\QueueContentAudioScriptGenerationAction;
use App\Domain\Content\Data\GenerateContentAudioScriptData;
use App\Domain\Content\Exceptions\ContentAudioScriptConflictException;
use App\Domain\Content\Exceptions\ContentAudioScriptQueueException;
use App\Domain\Content\Support\ContentAudioScriptJob;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\GenerateContentAudioScriptRenderRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class GenerateContentAudioScriptRenderController extends Controller
{
    public function __invoke(
        GenerateContentAudioScriptRenderRequest $request,
        QueueContentAudioScriptGenerationAction $queue,
        string $episodeId,
    ): JsonResponse {
        try {
            $job = $queue->handle(
                AuthenticatedUser::id($request),
                $request->convoLabUserId(),
                $episodeId,
                GenerateContentAudioScriptData::render(),
            );
        } catch (ContentAudioScriptConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (ContentAudioScriptQueueException) {
            return response()->json(['message' => ContentAudioScriptJob::QUEUE_FAILED_MESSAGE], 503);
        }

        return response()->json([
            'jobId' => $job->id,
            'message' => $job->wasRecentlyCreated
                ? 'Script audio rendering started.'
                : 'Script audio rendering already in progress.',
            ...($job->wasRecentlyCreated ? [] : ['existing' => true]),
        ]);
    }
}
