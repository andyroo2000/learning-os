<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\ListCardsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\ListCardsRequest;
use App\Http\Resources\Flashcards\CardResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListCardsController extends Controller
{
    public function __invoke(ListCardsRequest $request, ListCardsAction $listCards): AnonymousResourceCollection
    {
        $userId = AuthenticatedUser::id($request);

        return CardResource::collection(
            $listCards->handle(
                userId: $userId,
                pageSize: $request->pageSize(),
                courseId: $request->courseId(),
                studyStatus: $request->studyStatus(),
                cardType: $request->cardType(),
                q: $request->searchQuery(),
                deckId: $request->deckId(),
            )->withQueryString()
        );
    }
}
