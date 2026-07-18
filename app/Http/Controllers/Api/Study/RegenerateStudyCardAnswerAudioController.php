<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\RegenerateStudyCardAnswerAudioAction;
use App\Domain\Study\Exceptions\StudyCardAudioConflictException;
use App\Domain\Study\Exceptions\StudyCardAudioValidationException;
use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\RegenerateStudyCardAnswerAudioRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RegenerateStudyCardAnswerAudioController extends Controller
{
    public function __invoke(
        RegenerateStudyCardAnswerAudioRequest $request,
        RegenerateStudyCardAnswerAudioAction $regenerateAnswerAudio,
    ): JsonResponse {
        try {
            $card = $regenerateAnswerAudio->handle($request->studyCard(), $request->regenerationData());
        } catch (StudyCardAudioValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => [$exception->getMessage()],
            ]);
        } catch (StudyCardAudioConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (StudyPreviewMediaGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->httpStatus());
        }

        return response()->json(StudyCardSummaryResource::make($card)->resolve($request));
    }
}
