<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyBrowserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ListStudyBrowserRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class ListStudyBrowserController extends Controller
{
    public function __invoke(ListStudyBrowserRequest $request, ListStudyBrowserAction $listStudyBrowser): JsonResponse
    {
        return response()->json($listStudyBrowser->handle(
            userId: AuthenticatedUser::id($request),
            q: $request->searchQuery(),
            noteType: $request->noteType(),
            cardType: $request->cardType(),
            queueState: $request->queueState(),
            sortField: $request->sortField(),
            sortDirection: $request->sortDirection(),
            cursor: $request->cursor(),
            limit: $request->limit(),
            courseId: $request->courseId(),
            deckId: $request->deckId(),
        ));
    }
}
