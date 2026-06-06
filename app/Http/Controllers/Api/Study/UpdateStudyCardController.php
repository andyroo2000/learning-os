<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\UpdateStudyCardRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use Illuminate\Http\JsonResponse;

class UpdateStudyCardController extends Controller
{
    public function __invoke(UpdateStudyCardRequest $request, UpdateCardAction $updateCard): JsonResponse
    {
        $result = $updateCard->handle(
            $request->studyCard(),
            UpdateCardData::fromInput(
                frontText: $request->frontText(),
                backText: $request->backText(),
                hasPromptJson: true,
                promptJson: $request->promptPayload(),
                hasAnswerJson: true,
                answerJson: $request->answerPayload(),
            ),
        );

        // UpdateCardResult always carries the card, including unchanged no-op updates.
        return response()->json(StudyCardSummaryResource::make($result->card)->resolve($request));
    }
}
