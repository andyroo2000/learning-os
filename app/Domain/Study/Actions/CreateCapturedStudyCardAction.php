<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Actions\PromoteNewCardToFrontAction;
use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Data\CaptureStudyCardData;

class CreateCapturedStudyCardAction
{
    public function __construct(
        private readonly CreateCardAction $create,
        private readonly UpdateCardAction $update,
        private readonly PromoteNewCardToFrontAction $promote,
    ) {}

    public function handle(int $userId, string $deckId, CaptureStudyCardData $data, array $media): Card
    {
        $prompt = ['cueAudio' => $media['audio']->mediaRef];
        if (isset($media['image'])) {
            $prompt['cueImage'] = $media['image']->mediaRef;
        }
        $result = $this->create->handle(CreateCardData::fromInput(
            userId: $userId,
            deckId: $deckId,
            id: $data->id,
            frontText: $data->answer['expression'],
            backText: $data->answer['meaning'],
            promptJson: $prompt,
            answerJson: [...$data->answer, 'answerAudio' => $media['audio']->mediaRef],
        ));
        $this->update->handle($result->card, UpdateCardData::fromInput(
            frontText: $data->answer['expression'],
            backText: $data->answer['meaning'],
            hasFrontText: false,
            hasBackText: false,
            hasAnswerAudioSource: true,
            answerAudioSource: 'imported',
        ));

        return $this->promote->handle($userId, $result->card->id);
    }
}
