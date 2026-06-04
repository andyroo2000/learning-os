<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\ListDueCardsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\ListDueCardsRequest;
use App\Http\Resources\Flashcards\CardResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListDueCardsController extends Controller
{
    public function __invoke(ListDueCardsRequest $request, ListDueCardsAction $listDueCards): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return CardResource::collection(
            $listDueCards->handle(
                userId: $user->id,
                pageSize: $request->pageSize(),
                courseId: $request->courseId(),
            )->withQueryString()
        );
    }
}
