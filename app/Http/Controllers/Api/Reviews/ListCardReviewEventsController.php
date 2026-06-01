<?php

namespace App\Http\Controllers\Api\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ListCardReviewEventsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\ListCardReviewEventsRequest;
use App\Http\Resources\Reviews\CardReviewEventResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListCardReviewEventsController extends Controller
{
    public function __invoke(ListCardReviewEventsRequest $request, Card $card, ListCardReviewEventsAction $listCardReviewEvents): AnonymousResourceCollection
    {
        $this->authorize('view', $card);

        return CardReviewEventResource::collection(
            $listCardReviewEvents->handle($card, $request->perPage())->withQueryString()
        );
    }
}
