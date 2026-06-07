<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ShowStudyCardDraftAction;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyCardDraftResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ShowStudyCardDraftController extends Controller
{
    public function __invoke(
        Request $request,
        string $draftId,
        ShowStudyCardDraftAction $showStudyCardDraft,
    ): JsonResponse {
        $userId = AuthenticatedUser::id($request);

        try {
            $draft = $showStudyCardDraft->handle($userId, $draftId);
        } catch (StudyCardDraftNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }

        return response()->json(StudyCardDraftResource::make($draft)->resolve($request));
    }
}
