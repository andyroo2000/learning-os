<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\PerformCardStudyAction;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\PerformCardStudyActionRequest;
use App\Http\Resources\Flashcards\CardStudyActionResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class PerformCardStudyActionController extends Controller
{
    public function __invoke(
        PerformCardStudyActionRequest $request,
        Card $card,
        PerformCardStudyAction $performCardStudyAction,
        GetStudyOverviewAction $getStudyOverview,
    ): JsonResponse {
        $data = $request->validated();

        $result = $performCardStudyAction->handle(
            card: $card,
            action: $data['action'],
            mode: $data['mode'] ?? null,
            dueAt: $data['due_at'] ?? null,
            timeZone: $data['time_zone'] ?? null,
        );

        return CardStudyActionResource::make((object) [
            'card' => $result->card,
            'overview' => $getStudyOverview->handle(
                userId: AuthenticatedUser::id($request),
                timeZone: $data['time_zone'] ?? null,
                deckId: $request->deckId(),
            ),
        ])
            ->response()
            ->setStatusCode(200);
    }
}
