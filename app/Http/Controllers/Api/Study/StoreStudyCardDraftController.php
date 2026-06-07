<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\CreateStudyCardDraftAction;
use App\Domain\Study\Data\CreateStudyCardDraftData;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\StoreStudyCardDraftRequest;
use App\Http\Resources\Study\StudyCardDraftResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class StoreStudyCardDraftController extends Controller
{
    public function __invoke(
        StoreStudyCardDraftRequest $request,
        CreateStudyCardDraftAction $createStudyCardDraft,
    ): JsonResponse {
        try {
            $userId = AuthenticatedUser::id($request);

            $draft = $createStudyCardDraft->handle(CreateStudyCardDraftData::fromInput(
                userId: $userId,
                creationKind: $request->creationKind(),
                cardType: $request->cardType(),
                promptJson: $request->promptPayload(),
                answerJson: $request->answerPayload(),
                imagePlacement: $request->imagePlacement(),
                imagePrompt: $request->imagePrompt(),
            ));
        } catch (StudyCardDraftValidationException $exception) {
            // HTTP validation catches these first; this maps direct action guards defensively.
            throw ValidationException::withMessages([
                $exception->field() => [$exception->getMessage()],
            ]);
        } catch (StudyCardDraftConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        // Resolve manually to preserve ConvoLab's unwrapped compatibility response shape.
        return response()->json(StudyCardDraftResource::make($draft)->resolve($request), 201);
    }
}
