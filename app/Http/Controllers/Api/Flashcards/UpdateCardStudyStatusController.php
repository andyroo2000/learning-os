<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\UpdateCardStudyStatusAction;
use App\Domain\Flashcards\Models\Card;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\UpdateCardStudyStatusRequest;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Http\JsonResponse;

class UpdateCardStudyStatusController extends Controller
{
    public function __invoke(
        UpdateCardStudyStatusRequest $request,
        Card $card,
        UpdateCardStudyStatusAction $updateCardStudyStatus,
    ): JsonResponse {
        $data = $request->validated();

        $result = $updateCardStudyStatus->handle($card, $data['study_status']);

        return CardResource::make($result->card)
            ->response();
    }
}
