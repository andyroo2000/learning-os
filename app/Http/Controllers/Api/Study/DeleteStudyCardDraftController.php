<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\DeleteStudyCardDraftAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeleteStudyCardDraftController extends Controller
{
    public function __invoke(
        Request $request,
        string $draftId,
        DeleteStudyCardDraftAction $deleteStudyCardDraft,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        $deleteStudyCardDraft->handle($user->id, $draftId);

        return response()->noContent();
    }
}
