<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\UploadStudyImportFileAction;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Exceptions\StudyImportUploadExpiredException;
use App\Domain\Study\Exceptions\StudyImportValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\UploadStudyImportFileRequest;
use App\Http\Resources\Study\StudyImportJobResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UploadStudyImportFileController extends Controller
{
    public function __invoke(
        UploadStudyImportFileRequest $request,
        string $studyImportJobId,
        UploadStudyImportFileAction $uploadStudyImportFile,
    ): JsonResponse {
        try {
            $importJob = $uploadStudyImportFile->handle(
                userId: $request->user()->id,
                importJobId: $studyImportJobId,
                contents: $request->contents(),
                contentType: $request->contentType(),
                contentSizeBytes: $request->contentSizeBytes(),
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
        } catch (StudyImportValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => $exception->getMessage(),
            ]);
        }

        return StudyImportJobResource::make($importJob)
            ->response()
            ->setStatusCode(200);
    }
}
