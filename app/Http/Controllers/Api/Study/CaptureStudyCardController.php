<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Flashcards\Exceptions\CardConflictException;
use App\Domain\Study\Actions\CaptureStudyCardAction;
use App\Domain\Study\Exceptions\StudyCardAudioValidationException;
use App\Domain\Study\Exceptions\StudyCardImageValidationException;
use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\CaptureStudyCardRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class CaptureStudyCardController extends Controller
{
    public function __invoke(CaptureStudyCardRequest $request, CaptureStudyCardAction $capture): JsonResponse
    {
        $userId = AuthenticatedUser::id($request);
        try {
            $result = $capture->handle($userId, $request->captureData(), [
                'audio' => $request->uploadedAudio(),
                'image' => $request->uploadedImage(),
            ]);
        } catch (StudyCardAudioValidationException|StudyCardImageValidationException $exception) {
            throw ValidationException::withMessages([$exception->field() => [$exception->getMessage()]]);
        } catch (CardConflictException $exception) {
            return $this->conflict($exception, $userId);
        } catch (StudyPreviewMediaGenerationException $exception) {
            return response()->json(
                ['message' => $exception->getMessage()],
                $exception->httpStatus(),
                $exception->responseHeaders(),
            );
        }

        return response()->json(
            StudyCardSummaryResource::make($result->card)->resolve($request),
            $result->wasCreated ? 201 : 200,
        );
    }

    private function conflict(CardConflictException $exception, int $userId): JsonResponse
    {
        if (! $exception->isOwnedBy($userId)) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        return response()->json(['message' => $exception->getMessage()], $exception->isDeleted() ? 410 : 409);
    }
}
