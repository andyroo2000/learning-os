<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\ListDecksAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\ListDecksRequest;
use App\Http\Resources\Flashcards\DeckResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListDecksController extends Controller
{
    public function __invoke(ListDecksRequest $request, ListDecksAction $listDecks): AnonymousResourceCollection
    {
        $userId = AuthenticatedUser::id($request);

        return DeckResource::collection(
            $listDecks->handle($userId, $request->pageSize(), $request->courseId())->withQueryString()
        );
    }
}
