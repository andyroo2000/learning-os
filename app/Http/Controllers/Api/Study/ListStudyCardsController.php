<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Flashcards\Actions\ListCardsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\ListCardsRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class ListStudyCardsController extends Controller
{
    public function __invoke(
        ListCardsRequest $request,
        ListCardsAction $listCards,
    ): JsonResponse {
        $pageSize = $request->pageSize();
        $page = $listCards->handle(
            userId: AuthenticatedUser::id($request),
            pageSize: $pageSize,
            courseId: $request->courseId(),
            studyStatus: $request->studyStatus(),
            cardType: $request->cardType(),
            q: $request->searchQuery(),
            deckId: $request->deckId(),
        );

        return response()->json([
            'items' => StudyCardSummaryResource::collection($page->items())->resolve($request),
            'limit' => $pageSize->value(),
            'nextCursor' => $page->nextCursor()?->encode(),
        ]);
    }
}
