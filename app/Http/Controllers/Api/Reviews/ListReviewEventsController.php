<?php

namespace App\Http\Controllers\Api\Reviews;

use App\Domain\Reviews\Actions\ListReviewEventsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\ListReviewEventsRequest;
use App\Http\Resources\Reviews\CardReviewEventResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListReviewEventsController extends Controller
{
    public function __invoke(ListReviewEventsRequest $request, ListReviewEventsAction $listReviewEvents): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return CardReviewEventResource::collection(
            $listReviewEvents->handle(
                userId: $user->id,
                pageSize: $request->pageSize(),
                courseId: $request->courseId(),
                cardId: $request->cardId(),
            )->withQueryString()
        );
    }
}
