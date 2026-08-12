<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\QueueIdempotentContentDialogueGenerationAction;
use App\Domain\Content\Exceptions\ContentDialogueGenerationQueueException;
use App\Domain\Content\Exceptions\ContentGenerationRequestConflictException;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Domain\Content\Support\ContentGenerationRequestState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\GenerateContentDialogueRequest;
use Illuminate\Http\JsonResponse;

final class GenerateContentDialogueController extends Controller
{
    public function __invoke(
        GenerateContentDialogueRequest $request,
        QueueIdempotentContentDialogueGenerationAction $queue,
    ): JsonResponse {
        $data = $request->generationData();

        try {
            $result = $queue->handle(
                $request->contentUserId(),
                $request->convoLabUserId(),
                $request->clientRequestId(),
                $data,
            );
        } catch (ContentGenerationRequestConflictException $exception) {
            return response()->json([
                'code' => ContentGenerationRequestConflictException::CODE,
                'message' => $exception->getMessage(),
            ], 409);
        } catch (ContentDialogueGenerationQueueException $exception) {
            return response()->json([
                'clientRequestId' => $exception->clientRequestId,
                'state' => ContentGenerationRequestState::FAILED,
                'message' => ContentDialogueGeneration::QUEUE_FAILED_MESSAGE,
            ], 503);
        }

        $generationRequest = $result->request;
        if ($generationRequest->state === ContentGenerationRequestState::FAILED) {
            return response()->json([
                'clientRequestId' => $generationRequest->client_request_id,
                'state' => $generationRequest->state,
                'message' => $generationRequest->error_message,
            ], $generationRequest->response_status ?? 500);
        }

        return response()->json([
            'clientRequestId' => $generationRequest->client_request_id,
            'state' => $generationRequest->state,
            'jobId' => $generationRequest->job_id,
            'message' => 'Dialogue generation started',
        ]);
    }
}
