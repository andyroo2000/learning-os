<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\CreateStudyImportUploadSessionAction;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Exceptions\StudyImportValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\StoreStudyImportRequest;
use App\Http\Resources\Study\StudyImportJobResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class StoreStudyImportController extends Controller
{
    public function __invoke(
        StoreStudyImportRequest $request,
        CreateStudyImportUploadSessionAction $createStudyImportUploadSession,
    ): JsonResponse {
        try {
            $result = $createStudyImportUploadSession->handle(
                userId: AuthenticatedUser::id($request),
                filename: $request->filename(),
                contentType: $request->contentType(),
            );
        } catch (StudyImportConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], 409);
        } catch (StudyImportValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'data' => [
                'import_job' => StudyImportJobResource::make($result->importJob)->resolve($request),
                'upload' => [
                    'method' => $result->method,
                    'url' => $result->url,
                    'headers' => $result->headers,
                ],
            ],
        ], 201);
    }
}
