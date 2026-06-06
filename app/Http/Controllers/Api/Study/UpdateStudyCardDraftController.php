<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\UpdateStudyCardDraftAction;
use App\Domain\Study\Data\UpdateStudyCardDraftData;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\UpdateStudyCardDraftRequest;
use App\Http\Resources\Study\StudyCardDraftResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateStudyCardDraftController extends Controller
{
    public function __invoke(
        UpdateStudyCardDraftRequest $request,
        UpdateStudyCardDraftAction $updateStudyCardDraft,
    ): JsonResponse {
        try {
            $hasPrompt = $request->hasPrompt();
            $hasAnswer = $request->hasAnswer();

            $draft = $updateStudyCardDraft->handle($request->studyCardDraft(), UpdateStudyCardDraftData::fromInput(
                hasPrompt: $hasPrompt,
                promptJson: $hasPrompt ? $request->promptPayload() : null,
                hasAnswer: $hasAnswer,
                answerJson: $hasAnswer ? $request->answerPayload() : null,
                hasImagePlacement: $request->hasImagePlacement(),
                imagePlacement: $request->imagePlacement(),
                hasImagePrompt: $request->hasImagePrompt(),
                imagePrompt: $request->imagePrompt(),
                hasPreviewAudio: $request->hasPreviewAudio(),
                previewAudioJson: $request->previewAudio(),
                hasPreviewAudioRole: $request->hasPreviewAudioRole(),
                previewAudioRole: $request->previewAudioRole(),
                hasPreviewImage: $request->hasPreviewImage(),
                previewImageJson: $request->previewImage(),
            ));
        } catch (StudyCardDraftValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => [$exception->getMessage()],
            ]);
        } catch (StudyCardDraftConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        } catch (StudyCardDraftNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }

        return response()->json(StudyCardDraftResource::make($draft)->resolve($request));
    }
}
