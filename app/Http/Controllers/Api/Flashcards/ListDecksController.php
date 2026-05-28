<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\ListDecksAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Flashcards\DeckResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListDecksController extends Controller
{
    public function __invoke(Request $request, ListDecksAction $listDecks): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return DeckResource::collection($listDecks->handle($user->id));
    }
}
