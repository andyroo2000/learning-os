<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\QueueContentCourseGenerationAction;
use App\Domain\Content\Actions\RunQuotaLimitedContentGenerationAction;
use App\Domain\Content\Enums\ContentGenerationType;
use App\Domain\Content\Exceptions\ContentCourseGenerationConflictException;
use App\Domain\Content\Exceptions\ContentCourseGenerationQueueException;
use App\Domain\Content\Support\ContentCourseGeneration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\MutateContentCourseGenerationRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class GenerateContentCourseController extends Controller
{
    public function __invoke(
        MutateContentCourseGenerationRequest $request,
        QueueContentCourseGenerationAction $queue,
        RunQuotaLimitedContentGenerationAction $generation,
        string $courseId,
    ): JsonResponse {
        try {
            $result = $generation->handle(
                $request->convoLabUserId(),
                ContentGenerationType::Course,
                null,
                fn () => $queue->handle(
                    AuthenticatedUser::id($request),
                    $request->convoLabUserId(),
                    $courseId,
                ),
                fn ($started): string => $started->course->id,
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
            'message' => 'Course generation started',
            'jobId' => $result->course->id,
            'courseId' => $result->course->id,
        ]);
    }
}
