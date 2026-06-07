<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyExportDecksAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Flashcards\DeckResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListStudyExportDecksController extends Controller
{
    public function __invoke(Request $request, ListStudyExportDecksAction $listStudyExportDecks): AnonymousResourceCollection
    {
        $userId = AuthenticatedUser::id($request);

        return DeckResource::collection(
            $listStudyExportDecks->handle($userId),
        );
    }
}
