<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\FailStudyCardDraftAction;
use App\Domain\Study\Actions\FailStudyVocabBundleDraftsAction;
use App\Domain\Study\Actions\RetryStudyCardDraftAction;
use App\Domain\Study\Actions\RetryStudyVocabBundleDraftsAction;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyCardDraftResource;
use App\Http\Support\AuthenticatedUser;
use App\Jobs\ProcessStudyCardDraft;
use App\Jobs\ProcessStudyVocabBundleDrafts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class RetryStudyCardDraftController extends Controller
{
    public function __invoke(
        Request $request,
        string $draftId,
        RetryStudyCardDraftAction $retryStudyCardDraft,
        RetryStudyVocabBundleDraftsAction $retryStudyVocabBundleDrafts,
        FailStudyCardDraftAction $failStudyCardDraft,
        FailStudyVocabBundleDraftsAction $failStudyVocabBundleDrafts,
    ): JsonResponse {
        try {
            $userId = AuthenticatedUser::id($request);
            $dispatchFailed = false;
            $draft = $retryStudyVocabBundleDrafts->handleIfBundle(
                $userId,
                $draftId,
                afterCommit: static function (string $groupId) use (
                    &$dispatchFailed,
                    $failStudyVocabBundleDrafts,
                ): void {
                    try {
                        ProcessStudyVocabBundleDrafts::dispatch($groupId);
                    } catch (Throwable $exception) {
                        $dispatchFailed = true;
                        report($exception);
                        $failStudyVocabBundleDrafts->handle(
                            $groupId,
                            ProcessStudyVocabBundleDrafts::EXHAUSTED_ERROR_MESSAGE,
                        );
                    }
                },
            );
            if ($draft === null) {
                $draft = $retryStudyCardDraft->handle(
                    $userId,
                    $draftId,
                    afterCommit: static function (string $processedDraftId) use (
                        &$dispatchFailed,
                        $failStudyCardDraft,
                    ): void {
                        try {
                            ProcessStudyCardDraft::dispatch($processedDraftId);
                        } catch (Throwable $exception) {
                            $dispatchFailed = true;
                            report($exception);
                            $failStudyCardDraft->handle(
                                $processedDraftId,
                                ProcessStudyCardDraft::EXHAUSTED_ERROR_MESSAGE,
                            );
                        }
                    },
                );
            }
            if ($dispatchFailed) {
                $draft->refresh();
            }
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
