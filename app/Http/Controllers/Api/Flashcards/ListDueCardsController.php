<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\ListDueCardsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\ListDueCardsRequest;
use App\Http\Resources\Flashcards\CardResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListDueCardsController extends Controller
{
    public function __invoke(ListDueCardsRequest $request, ListDueCardsAction $listDueCards): AnonymousResourceCollection
    {
        $userId = AuthenticatedUser::id($request);

        return CardResource::collection(
            $listDueCards->handle(
                userId: $userId,
                pageSize: $request->pageSize(),
                courseId: $request->courseId(),
                deckId: $request->deckId(),
            )->withQueryString()
        );
    }
}
