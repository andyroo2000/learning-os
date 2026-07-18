<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\PrepareStudyCardAnswerAudioAction;
use App\Domain\Study\Exceptions\StudyCardAudioConflictException;
use App\Domain\Study\Exceptions\StudyCardAudioValidationException;
use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\PrepareStudyCardAnswerAudioRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class PrepareStudyCardAnswerAudioController extends Controller
{
    public function __invoke(
        PrepareStudyCardAnswerAudioRequest $request,
        PrepareStudyCardAnswerAudioAction $prepareAnswerAudio,
    ): JsonResponse {
        try {
            $card = $prepareAnswerAudio->handle($request->studyCard());
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
