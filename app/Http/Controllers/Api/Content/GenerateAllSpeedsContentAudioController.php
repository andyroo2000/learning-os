<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\QueueContentAudioGenerationAction;
use App\Domain\Content\Exceptions\ContentAudioGenerationQueueException;
use App\Domain\Content\Support\ContentAudioGeneration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\GenerateAllSpeedsContentAudioRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class GenerateAllSpeedsContentAudioController extends Controller
{
    public function __invoke(
        GenerateAllSpeedsContentAudioRequest $request,
        QueueContentAudioGenerationAction $queue,
    ): JsonResponse {
        try {
            $job = $queue->handle(
                AuthenticatedUser::id($request),
                $request->convoLabUserId(),
                $request->generationData(),
            );
        } catch (ContentAudioGenerationQueueException) {
            return response()->json(['message' => ContentAudioGeneration::QUEUE_FAILED_MESSAGE], 503);
        }
        if ($job === null) {
            return response()->json(['message' => 'Episode or dialogue not found'], 404);
        }

        $response = [
            'jobId' => $job->id,
            'message' => $job->wasRecentlyCreated
                ? 'Multi-speed audio generation started'
                : 'Audio generation already in progress',
        ];
        if (! $job->wasRecentlyCreated) {
            $response['existing'] = true;
        }

        return response()->json($response);
    }
}
