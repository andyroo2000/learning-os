<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\DeleteStudyCardDraftAction;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeleteStudyCardDraftController extends Controller
{
    public function __invoke(
        Request $request,
        string $draftId,
        DeleteStudyCardDraftAction $deleteStudyCardDraft,
    ): Response {
        $userId = AuthenticatedUser::id($request);

        $deleteStudyCardDraft->handle($userId, $draftId);

        return response()->noContent();
    }
}
