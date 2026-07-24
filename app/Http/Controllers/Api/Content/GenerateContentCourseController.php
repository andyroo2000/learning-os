<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\CheckContentGenerationEligibilityAction;
use App\Domain\Content\Actions\QueueContentCourseGenerationAction;
use App\Domain\Content\Actions\RunQuotaLimitedContentGenerationAction;
use App\Domain\Content\Enums\ContentGenerationType;
use App\Domain\Content\Exceptions\ContentCourseGenerationConflictException;
use App\Domain\Content\Exceptions\ContentCourseGenerationQueueException;
use App\Domain\Content\Support\ContentCourseGeneration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\GenerateContentCourseRequest;
use Illuminate\Http\JsonResponse;

final class GenerateContentCourseController extends Controller
{
    public function __invoke(
        GenerateContentCourseRequest $request,
        QueueContentCourseGenerationAction $queue,
        CheckContentGenerationEligibilityAction $eligibility,
        RunQuotaLimitedContentGenerationAction $generation,
        string $courseId,
    ): JsonResponse {
        try {
            if (! $eligibility->course(
                $request->contentUserId(),
                $request->convoLabUserId(),
                $courseId,
                retryOnly: false,
            )) {
                return response()->json(['message' => 'Course not found'], 404);
            }

            $result = $generation->handle(
                $request->convoLabUserId(),
                ContentGenerationType::Course,
                null,
                fn () => $queue->handle(
                    $request->contentUserId(),
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
