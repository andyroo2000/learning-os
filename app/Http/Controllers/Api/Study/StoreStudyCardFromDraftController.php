<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Flashcards\Exceptions\CardConflictException;
use App\Domain\Flashcards\Exceptions\CardValidationException;
use App\Domain\Study\Actions\CreateStudyCardFromDraftAction;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\StoreStudyCardFromDraftRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StoreStudyCardFromDraftController extends Controller
{
    public function __invoke(
        StoreStudyCardFromDraftRequest $request,
        string $draftId,
        CreateStudyCardFromDraftAction $createStudyCardFromDraft,
    ): JsonResponse {
        $userId = AuthenticatedUser::id($request);

        try {
            $result = $createStudyCardFromDraft->handle($userId, $draftId, $request->id());
        } catch (StudyCardDraftNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (StudyCardDraftConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        } catch (CardConflictException $exception) {
            // Hide cross-user IDs before returning same-user deletion details.
            if (! $exception->isOwnedBy($userId)) {
                return response()->json([
                    'message' => 'Not Found',
                ], 404);
            }

            if ($exception->isDeleted()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'reason' => $exception->reason(),
                ], 410);
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], 409);
        } catch (CardValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => [$exception->getMessage()],
            ]);
        }

        // Resolve manually to preserve ConvoLab's unwrapped compatibility response shape.
        return response()->json(
            StudyCardSummaryResource::make($result->card)->resolve($request),
            $result->wasCreated ? 201 : 200,
        );
    }
}
