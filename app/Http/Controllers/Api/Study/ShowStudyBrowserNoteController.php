<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ShowStudyBrowserNoteAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyCardSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowStudyBrowserNoteController extends Controller
{
    public function __invoke(Request $request, string $noteId, ShowStudyBrowserNoteAction $showStudyBrowserNote): JsonResponse
    {
        $result = $showStudyBrowserNote->handle(
            userId: (int) $request->user()->id,
            noteId: $noteId,
        );

        if ($result === null) {
            return response()->json(['message' => 'Study note not found.'], 404);
        }

        $cards = $result['cards'];
        $result['cards'] = StudyCardSummaryResource::collection($cards)->resolve($request);

        return response()->json($result);
    }
}
