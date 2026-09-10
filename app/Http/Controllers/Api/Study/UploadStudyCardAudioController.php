<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\UploadStudyCardAudioAction;
use App\Domain\Study\Exceptions\StudyCardAudioConflictException;
use App\Domain\Study\Exceptions\StudyCardAudioValidationException;
use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\UploadStudyCardAudioRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UploadStudyCardAudioController extends Controller
{
    public function __invoke(
        UploadStudyCardAudioRequest $request,
        UploadStudyCardAudioAction $uploadAudio,
    ): JsonResponse {
        try {
            $card = $uploadAudio->handle(
                $request->studyCard(),
                $request->uploadedAudio(),
            );
        } catch (StudyCardAudioConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (StudyCardAudioValidationException $exception) {
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
