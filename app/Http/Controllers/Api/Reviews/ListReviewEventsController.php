<?php

namespace App\Http\Controllers\Api\Reviews;

use App\Domain\Reviews\Actions\ListReviewEventsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\ListReviewEventsRequest;
use App\Http\Resources\Reviews\CardReviewEventResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListReviewEventsController extends Controller
{
    public function __invoke(ListReviewEventsRequest $request, ListReviewEventsAction $listReviewEvents): AnonymousResourceCollection
    {
        $userId = AuthenticatedUser::id($request);

        return CardReviewEventResource::collection(
            $listReviewEvents->handle(
                userId: $userId,
                pageSize: $request->pageSize(),
                courseId: $request->courseId(),
                deckId: $request->deckId(),
                cardId: $request->cardId(),
            )->withQueryString()
        );
    }
}
