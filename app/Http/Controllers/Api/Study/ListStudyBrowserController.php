<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyBrowserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ListStudyBrowserRequest;
use Illuminate\Http\JsonResponse;

class ListStudyBrowserController extends Controller
{
    public function __invoke(ListStudyBrowserRequest $request, ListStudyBrowserAction $listStudyBrowser): JsonResponse
    {
        return response()->json($listStudyBrowser->handle(
            userId: (int) $request->user()->id,
            q: $request->searchQuery(),
            noteType: $request->noteType(),
            cardType: $request->cardType(),
            queueState: $request->queueState(),
            sortField: $request->sortField(),
            sortDirection: $request->sortDirection(),
            cursor: $request->cursor(),
            limit: $request->limit(),
        ));
    }
}
