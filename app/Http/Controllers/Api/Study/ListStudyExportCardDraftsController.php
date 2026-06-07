<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyExportCardDraftsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyCardDraftResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListStudyExportCardDraftsController extends Controller
{
    public function __invoke(
        Request $request,
        ListStudyExportCardDraftsAction $listStudyExportCardDrafts,
    ): AnonymousResourceCollection {
        $userId = AuthenticatedUser::id($request);

        return StudyCardDraftResource::collection(
            $listStudyExportCardDrafts->handle($userId),
        );
    }
}
