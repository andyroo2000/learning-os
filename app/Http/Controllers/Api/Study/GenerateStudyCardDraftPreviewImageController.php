<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\GenerateStudyCardDraftPreviewImageAction;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Domain\Study\Exceptions\StudyCardDraftRevisionConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\GenerateStudyCardDraftPreviewRequest;
use App\Http\Resources\Study\StudyCardDraftResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GenerateStudyCardDraftPreviewImageController extends Controller
{
    public function __invoke(
        GenerateStudyCardDraftPreviewRequest $request,
        GenerateStudyCardDraftPreviewImageAction $generatePreviewImage,
    ): JsonResponse {
        try {
            $draft = $generatePreviewImage->handle($request->studyCardDraft());
        } catch (StudyCardDraftRevisionConflictException $exception) {
            return response()->json([
                'code' => StudyCardDraftRevisionConflictException::CODE,
                'message' => $exception->getMessage(),
                'draft' => StudyCardDraftResource::make($exception->draft)->resolve($request),
            ], 409);
        } catch (StudyCardDraftValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => [$exception->getMessage()],
            ]);
        } catch (StudyCardDraftConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (StudyCardDraftNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (StudyPreviewMediaGenerationException $exception) {
            return response()->json(
                ['message' => $exception->getMessage()],
                $exception->httpStatus(),
                $exception->responseHeaders(),
            );
        }

        return response()->json([
            'revision' => $draft->revision,
            'imagePrompt' => $draft->image_prompt,
            'imagePlacement' => $draft->image_placement?->value,
            'previewImage' => $draft->preview_image_json,
        ]);
    }
}
