<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyExportDecksAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Flashcards\DeckResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListStudyExportDecksController extends Controller
{
    public function __invoke(Request $request, ListStudyExportDecksAction $listStudyExportDecks): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return DeckResource::collection(
            $listStudyExportDecks->handle($user->id),
        );
    }
}
