<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Http\Controllers\Controller;
use App\Http\Resources\Flashcards\CardResource;

class ShowCardController extends Controller
{
    public function __invoke(Card $card): CardResource
    {
        $this->authorize('view', $card);

        return CardResource::make($card);
    }
}
