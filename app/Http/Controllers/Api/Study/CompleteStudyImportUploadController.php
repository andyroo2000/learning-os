<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\CompleteStudyImportUploadAction;
use App\Domain\Study\Exceptions\StudyImportArchiveException;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Exceptions\StudyImportUploadExpiredException;
use App\Domain\Study\Exceptions\StudyImportValidationException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyImportJobResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CompleteStudyImportUploadController extends Controller
{
    public function __invoke(
        Request $request,
        string $studyImportJobId,
        CompleteStudyImportUploadAction $completeStudyImportUpload,
    ): JsonResponse {
        try {
            $importJob = $completeStudyImportUpload->handle(
                userId: $request->user()->id,
                importJobId: $studyImportJobId,
            );
        } catch (StudyImportConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], 409);
        } catch (StudyImportUploadExpiredException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], 410);
        } catch (StudyImportArchiveException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], $exception->statusCode());
        } catch (StudyImportValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => $exception->getMessage(),
            ]);
        }

        return StudyImportJobResource::make($importJob)
            ->response()
            ->setStatusCode(202);
    }
}
