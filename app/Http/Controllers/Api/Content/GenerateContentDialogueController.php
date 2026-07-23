<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\QueueContentDialogueGenerationAction;
use App\Domain\Content\Actions\RunQuotaLimitedContentGenerationAction;
use App\Domain\Content\Enums\ContentGenerationType;
use App\Domain\Content\Exceptions\ContentDialogueGenerationConflictException;
use App\Domain\Content\Exceptions\ContentDialogueGenerationQueueException;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\GenerateContentDialogueRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class GenerateContentDialogueController extends Controller
{
    public function __invoke(
        GenerateContentDialogueRequest $request,
        QueueContentDialogueGenerationAction $queue,
        RunQuotaLimitedContentGenerationAction $generation,
    ): JsonResponse {
        $data = $request->generationData();

        try {
            $job = $generation->handle(
                $request->convoLabUserId(),
                ContentGenerationType::Dialogue,
                $data->episodeId,
                fn () => $queue->handle(
                    AuthenticatedUser::id($request),
                    $request->convoLabUserId(),
                    $data,
                ),
                fn ($result): string => $result->episode_id,
            );
        } catch (ContentDialogueGenerationConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        } catch (ContentDialogueGenerationQueueException) {
            return response()->json(['message' => ContentDialogueGeneration::QUEUE_FAILED_MESSAGE], 503);
        }

        if ($job === null) {
            return response()->json(['message' => 'Episode not found'], 404);
        }

        return response()->json([
            'jobId' => $job->id,
            'message' => 'Dialogue generation started',
        ]);
    }
}
