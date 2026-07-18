<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\GenerateStudyCardDraftPreviewAudioAction;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\GenerateStudyCardDraftPreviewRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GenerateStudyCardDraftPreviewAudioController extends Controller
{
    public function __invoke(
        GenerateStudyCardDraftPreviewRequest $request,
        GenerateStudyCardDraftPreviewAudioAction $generatePreviewAudio,
    ): JsonResponse {
        try {
            $draft = $generatePreviewAudio->handle($request->studyCardDraft());
        } catch (StudyCardDraftValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => [$exception->getMessage()],
            ]);
        } catch (StudyCardDraftConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (StudyCardDraftNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (StudyPreviewMediaGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->httpStatus());
        }

        return response()->json([
            'previewAudio' => $draft->preview_audio_json,
            'previewAudioRole' => $draft->preview_audio_role?->value,
        ]);
    }
}
