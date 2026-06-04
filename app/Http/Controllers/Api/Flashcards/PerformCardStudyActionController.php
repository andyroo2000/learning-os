<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\SetCardDueAction;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\PerformCardStudyActionRequest;
use App\Http\Resources\Flashcards\CardStudyActionResource;
use Illuminate\Http\JsonResponse;

class PerformCardStudyActionController extends Controller
{
    public function __invoke(
        PerformCardStudyActionRequest $request,
        Card $card,
        SetCardDueAction $setCardDue,
        GetStudyOverviewAction $getStudyOverview,
    ): JsonResponse {
        $data = $request->validated();

        $result = $setCardDue->handle(
            card: $card,
            mode: $data['mode'],
            dueAt: $data['due_at'] ?? null,
            timeZone: $data['time_zone'] ?? null,
        );

        return CardStudyActionResource::make((object) [
            'card' => $result->card,
            'overview' => $getStudyOverview->handle(
                userId: (int) $request->user()->id,
                timeZone: $data['time_zone'] ?? null,
            ),
        ])
            ->response()
            ->setStatusCode(200);
    }
}
