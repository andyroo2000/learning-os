<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyPilesAction;
use App\Domain\Study\Actions\SaveStudyPileAction;
use App\Domain\Study\Actions\SaveStudyPileChapterAction;
use App\Domain\Study\Sync\StudyPilePayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ListStudyPilesRequest;
use App\Http\Requests\Study\SaveStudyPileRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class StudyPileController extends Controller
{
    public function index(ListStudyPilesRequest $request, ListStudyPilesAction $list): JsonResponse
    {
        return response()->json($list->handle(AuthenticatedUser::id($request), $request->cursor(), $request->limit()));
    }

    public function save(SaveStudyPileRequest $request, string $pileId, SaveStudyPileAction $save): JsonResponse
    {
        $pile = $save->handle(AuthenticatedUser::id($request), $pileId, $request->validated());

        return response()->json(['pile' => StudyPilePayload::withChapters($pile)]);
    }

    public function saveChapter(
        SaveStudyPileRequest $request,
        string $pileId,
        string $chapterId,
        SaveStudyPileChapterAction $save,
    ): JsonResponse {
        $chapter = $save->handle(AuthenticatedUser::id($request), $pileId, $chapterId, $request->validated());

        return response()->json(['chapter' => StudyPilePayload::record($chapter)]);
    }
}
