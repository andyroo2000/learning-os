<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\ListNewCardsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\ListNewCardsRequest;
use App\Http\Resources\Flashcards\CardResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListNewCardsController extends Controller
{
    public function __invoke(ListNewCardsRequest $request, ListNewCardsAction $listNewCards): AnonymousResourceCollection
    {
        $userId = AuthenticatedUser::id($request);

        return CardResource::collection(
            $listNewCards->handle(
                userId: $userId,
                pageSize: $request->pageSize(),
                courseId: $request->courseId(),
                deckId: $request->deckId(),
            )->withQueryString()
        );
    }
}
