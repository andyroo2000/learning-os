<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\CompleteStudyImportUploadAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportArchiveException;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Exceptions\StudyImportUploadExpiredException;
use App\Domain\Study\Exceptions\StudyImportValidationException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyImportJobResource;
use App\Http\Support\AuthenticatedUser;
use App\Jobs\ProcessStudyImportJob;
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
            $result = $completeStudyImportUpload->handle(
                userId: AuthenticatedUser::id($request),
                importJobId: $studyImportJobId,
            );
            $importJob = $result->importJob;
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

        if ($result->shouldDispatchImport) {
            ProcessStudyImportJob::dispatch($importJob->id);
        }

        return StudyImportJobResource::make($importJob)
            ->response()
            ->setStatusCode($this->statusCodeFor($importJob->status));
    }

    private function statusCodeFor(StudyImportStatus $status): int
    {
        return in_array($status, [
            StudyImportStatus::Pending,
            StudyImportStatus::Processing,
        ], true) ? 202 : 200;
    }
}
