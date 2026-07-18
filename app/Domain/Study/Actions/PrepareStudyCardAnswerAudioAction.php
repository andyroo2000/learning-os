<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Data\RegenerateStudyCardAnswerAudioData;

class PrepareStudyCardAnswerAudioAction
{
    public function __construct(
        private readonly RegenerateStudyCardAnswerAudioAction $regenerateAnswerAudio,
    ) {}

    public function handle(Card $card): Card
    {
        if ($this->hasPlayableAudio($card)) {
            return $card;
        }

        return $this->regenerateAnswerAudio->handle($card, RegenerateStudyCardAnswerAudioData::fromInput(
            hasVoiceId: false,
            voiceId: null,
            hasTextOverride: false,
            textOverride: null,
        ));
    }

    private function hasPlayableAudio(Card $card): bool
    {
        $answer = is_array($card->answer_json) ? $card->answer_json : [];
        $audio = $answer['answerAudio'] ?? null;

        if (! is_array($audio)
            || ! is_string($audio['url'] ?? null)
            || trim($audio['url']) === ''
            || ($card->answer_audio_source ?? 'missing') === 'missing') {
            return false;
        }

        $mediaId = $audio['id'] ?? null;

        return ! is_string($mediaId)
            || $card->mediaAssets()->whereKey($mediaId)->exists();
    }
}
