<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\QueueContentAudioGenerationAction;
use App\Domain\Content\Exceptions\ContentAudioGenerationConflictException;
use App\Domain\Content\Exceptions\ContentAudioGenerationQueueException;
use App\Domain\Content\Support\ContentAudioGeneration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\GenerateContentAudioRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class GenerateContentAudioController extends Controller
{
    public function __invoke(
        GenerateContentAudioRequest $request,
        QueueContentAudioGenerationAction $queue,
    ): JsonResponse {
        try {
            $job = $queue->handle(
                AuthenticatedUser::id($request),
                $request->convoLabUserId(),
                $request->generationData(),
            );
        } catch (ContentAudioGenerationConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (ContentAudioGenerationQueueException) {
            return response()->json(['message' => ContentAudioGeneration::QUEUE_FAILED_MESSAGE], 503);
        }
        if ($job === null) {
            return response()->json(['message' => 'Episode or dialogue not found'], 404);
        }

        return response()->json([
            'jobId' => $job->id,
            'message' => 'Audio generation started',
        ]);
    }
}
