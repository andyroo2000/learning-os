<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\ListDecksAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\ListDecksRequest;
use App\Http\Resources\Flashcards\DeckResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListDecksController extends Controller
{
    public function __invoke(ListDecksRequest $request, ListDecksAction $listDecks): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return DeckResource::collection(
            $listDecks->handle($user->id, $request->pageSize())->withQueryString()
        );
    }
}
