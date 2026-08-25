<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\LinkCardLearningPathSuccessorAction;
use App\Domain\Flashcards\Models\Card;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\LinkCardLearningPathSuccessorRequest;
use App\Http\Resources\Flashcards\CardLearningPathResource;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

class LinkCardLearningPathSuccessorController extends Controller
{
    public function __invoke(
        LinkCardLearningPathSuccessorRequest $request,
        Card $card,
        LinkCardLearningPathSuccessorAction $linkSuccessor,
    ): JsonResponse {
        $successorId = (string) $request->validated('successor_card_id');
        $successor = Card::query()
            ->whereIn('id', CanonicalUlid::databaseCandidates($successorId))
            ->first();

        if ($successor === null) {
            throw (new ModelNotFoundException)->setModel(Card::class, [$successorId]);
        }

        $this->authorize('update', $successor);

        $cards = $linkSuccessor->handle($card, $successor);

        return CardLearningPathResource::make([
            'anchor' => $card->refresh(),
            'cards' => $cards,
        ])->response();
    }
}
