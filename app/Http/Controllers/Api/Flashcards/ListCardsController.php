<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\ListCardsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\ListCardsRequest;
use App\Http\Resources\Flashcards\CardResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListCardsController extends Controller
{
    public function __invoke(ListCardsRequest $request, ListCardsAction $listCards): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return CardResource::collection(
            $listCards->handle(
                userId: $user->id,
                pageSize: $request->pageSize(),
                courseId: $request->courseId(),
                studyStatus: $request->studyStatus(),
                q: $request->searchQuery(),
            )->withQueryString()
        );
    }
}
