<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\UploadStudyCardImageAction;
use App\Domain\Study\Exceptions\StudyCardImageConflictException;
use App\Domain\Study\Exceptions\StudyCardImageValidationException;
use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\UploadStudyCardImageRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UploadStudyCardImageController extends Controller
{
    public function __invoke(
        UploadStudyCardImageRequest $request,
        UploadStudyCardImageAction $uploadImage,
    ): JsonResponse {
        try {
            $card = $uploadImage->handle(
                $request->studyCard(),
                $request->uploadedImage(),
                $request->imagePlacement(),
            );
        } catch (StudyCardImageConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (StudyCardImageValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => [$exception->getMessage()],
            ]);
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
