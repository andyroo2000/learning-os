<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\QueueContentCourseGenerationAction;
use App\Domain\Content\Exceptions\ContentCourseGenerationConflictException;
use App\Domain\Content\Exceptions\ContentCourseGenerationQueueException;
use App\Domain\Content\Support\ContentCourseGeneration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\MutateContentCourseGenerationRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class RetryContentCourseGenerationController extends Controller
{
    public function __invoke(
        MutateContentCourseGenerationRequest $request,
        QueueContentCourseGenerationAction $queue,
        string $courseId,
    ): JsonResponse {
        try {
            $result = $queue->handle(
                AuthenticatedUser::id($request),
                $request->convoLabUserId(),
                $courseId,
                retryOnly: true,
            );
        } catch (ContentCourseGenerationConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        } catch (ContentCourseGenerationQueueException) {
            return response()->json(['message' => ContentCourseGeneration::QUEUE_FAILED_MESSAGE], 503);
        }
        if ($result === null) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        return response()->json([
            'message' => 'Course generation retried',
            'jobId' => $result->course->id,
            'courseId' => $result->course->id,
        ]);
    }
}
