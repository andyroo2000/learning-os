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
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListDecksAction::MAX_PAGE_SIZE],
        ]);
        $perPage = $request->integer('per_page', ListDecksAction::MAX_PAGE_SIZE);

        return DeckResource::collection($listDecks->handle($user->id, $perPage));
    }
}
