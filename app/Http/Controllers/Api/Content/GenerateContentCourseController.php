<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\QueueIdempotentContentCourseGenerationAction;
use App\Domain\Content\Exceptions\ContentCourseGenerationQueueException;
use App\Domain\Content\Exceptions\ContentGenerationRequestConflictException;
use App\Domain\Content\Support\ContentCourseGeneration;
use App\Domain\Content\Support\ContentGenerationRequestState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\GenerateContentCourseRequest;
use Illuminate\Http\JsonResponse;

final class GenerateContentCourseController extends Controller
{
    public function __invoke(
        GenerateContentCourseRequest $request,
        QueueIdempotentContentCourseGenerationAction $queue,
        string $courseId,
    ): JsonResponse {
        try {
            $result = $queue->handle(
                $request->contentUserId(),
                $request->convoLabUserId(),
                $request->clientRequestId(),
                $courseId,
            );
        } catch (ContentGenerationRequestConflictException $exception) {
            return response()->json([
                'code' => ContentGenerationRequestConflictException::CODE,
                'message' => $exception->getMessage(),
            ], 409);
        } catch (ContentCourseGenerationQueueException $exception) {
            return response()->json([
                'clientRequestId' => $exception->clientRequestId,
                'state' => ContentGenerationRequestState::FAILED,
                'message' => ContentCourseGeneration::QUEUE_FAILED_MESSAGE,
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
            'message' => 'Course generation started',
            'jobId' => $generationRequest->job_id,
            'courseId' => $generationRequest->resource_id,
        ]);
    }
}
