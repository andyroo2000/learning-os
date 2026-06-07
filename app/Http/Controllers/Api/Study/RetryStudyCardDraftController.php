<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\RetryStudyCardDraftAction;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyCardDraftResource;
use App\Http\Support\AuthenticatedUser;
use App\Jobs\ProcessStudyCardDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RetryStudyCardDraftController extends Controller
{
    public function __invoke(
        Request $request,
        string $draftId,
        RetryStudyCardDraftAction $retryStudyCardDraft,
    ): JsonResponse {
        try {
            $draft = $retryStudyCardDraft->handle(
                AuthenticatedUser::id($request),
                $draftId,
                afterCommit: static fn (string $processedDraftId) => ProcessStudyCardDraft::dispatch($processedDraftId),
            );
        } catch (StudyCardDraftNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (StudyCardDraftConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json(StudyCardDraftResource::make($draft)->resolve($request));
    }
}
