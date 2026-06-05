<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\CancelStudyImportUploadAction;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyImportJobResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CancelStudyImportUploadController extends Controller
{
    public function __invoke(
        Request $request,
        string $studyImportJobId,
        CancelStudyImportUploadAction $cancelStudyImportUpload,
    ): JsonResponse {
        try {
            $importJob = $cancelStudyImportUpload->handle(
                userId: $request->user()->id,
                importJobId: $studyImportJobId,
            );
        } catch (StudyImportConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], 409);
        }

        return StudyImportJobResource::make($importJob)
            ->response()
            ->setStatusCode(200);
    }
}
