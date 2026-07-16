<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ShowStudyBrowserNoteAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyCardSummaryResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowStudyBrowserNoteController extends Controller
{
    public function __invoke(Request $request, string $noteId, ShowStudyBrowserNoteAction $showStudyBrowserNote): JsonResponse
    {
        $result = $showStudyBrowserNote->handle(
            userId: AuthenticatedUser::id($request),
            noteId: $noteId,
        );

        if ($result === null) {
            return response()->json(['message' => 'Study note not found.'], 404);
        }

        return response()->json([
            'noteId' => $result->noteId,
            'displayText' => $result->displayText,
            'noteTypeName' => $result->noteTypeName,
            'sourceKind' => $result->sourceKind,
            'reviewCount' => $result->reviewCount,
            'lastReviewedAt' => $result->lastReviewedAt,
            'updatedAt' => $result->updatedAt,
            'rawFields' => $result->rawFields,
            'canonicalFields' => $result->canonicalFields,
            'cards' => StudyCardSummaryResource::collection($result->cards)->resolve($request),
            'cardStats' => $result->cardStats,
            'selectedCardId' => $result->selectedCardId,
        ]);
    }
}
