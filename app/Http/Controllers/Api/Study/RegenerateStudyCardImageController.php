<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\RegenerateStudyCardImageAction;
use App\Domain\Study\Exceptions\StudyCardImageConflictException;
use App\Domain\Study\Exceptions\StudyCardImageValidationException;
use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\RegenerateStudyCardImageRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RegenerateStudyCardImageController extends Controller
{
    public function __invoke(
        RegenerateStudyCardImageRequest $request,
        RegenerateStudyCardImageAction $regenerateImage,
    ): JsonResponse {
        try {
            $card = $regenerateImage->handle($request->studyCard(), $request->regenerationData());
        } catch (StudyCardImageValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => [$exception->getMessage()],
            ]);
        } catch (StudyCardImageConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (StudyPreviewMediaGenerationException $exception) {
            return response()->json(
                ['message' => $exception->getMessage()],
                $exception->httpStatus(),
                $exception->responseHeaders(),
            );
        }

        return response()->json(StudyCardSummaryResource::make($card)->resolve($request));
    }
}
