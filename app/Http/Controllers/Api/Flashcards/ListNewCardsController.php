<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\ListNewCardsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\ListNewCardsRequest;
use App\Http\Resources\Flashcards\CardResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListNewCardsController extends Controller
{
    public function __invoke(ListNewCardsRequest $request, ListNewCardsAction $listNewCards): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return CardResource::collection(
            $listNewCards->handle(
                userId: $user->id,
                pageSize: $request->pageSize(),
                courseId: $request->courseId(),
                deckId: $request->deckId(),
            )->withQueryString()
        );
    }
}
