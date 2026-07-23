<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\QueueContentImageGenerationAction;
use App\Domain\Content\Exceptions\ContentImageGenerationQueueException;
use App\Domain\Content\Support\ContentImageGeneration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\GenerateContentImagesRequest;
use Illuminate\Http\JsonResponse;

final class GenerateContentImagesController extends Controller
{
    public function __invoke(
        GenerateContentImagesRequest $request,
        QueueContentImageGenerationAction $queue,
    ): JsonResponse {
        try {
            $job = $queue->handle(
                $request->contentUserId(),
                $request->convoLabUserId(),
                $request->generationData(),
            );
        } catch (ContentImageGenerationQueueException) {
            return response()->json(['message' => ContentImageGeneration::QUEUE_FAILED_MESSAGE], 503);
        }

        if ($job === null) {
            return response()->json(['message' => 'Episode or dialogue not found'], 404);
        }

        return response()->json([
            'jobId' => $job->id,
            'message' => 'Image generation started',
        ]);
    }
}
