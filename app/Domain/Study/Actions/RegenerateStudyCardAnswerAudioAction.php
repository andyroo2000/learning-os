<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Actions\DetachMediaFromCardAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Data\DetachMediaFromCardData;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Data\RegenerateStudyCardAnswerAudioData;
use App\Domain\Study\Exceptions\StudyCardAudioConflictException;
use App\Domain\Study\Exceptions\StudyCardAudioValidationException;
use App\Domain\Study\Services\FishAudioSpeechGenerator;
use App\Domain\Study\Support\StudyCardGenerationDefaults;
use App\Domain\Study\Support\StudyMediaGenerationRateLimiter;
use Illuminate\Support\Facades\DB;
use Throwable;

class RegenerateStudyCardAnswerAudioAction
{
    public function __construct(
        private readonly FishAudioSpeechGenerator $fishAudio,
        private readonly PersistGeneratedStudyMediaAction $persistGeneratedMedia,
        private readonly DiscardGeneratedStudyMediaAction $discardGeneratedMedia,
        private readonly UpdateCardAction $updateCard,
        private readonly AttachMediaToCardAction $attachMedia,
        private readonly DetachMediaFromCardAction $detachMedia,
        private readonly StudyMediaGenerationRateLimiter $generationRateLimiter,
    ) {}

    public function handle(Card $card, RegenerateStudyCardAnswerAudioData $data): Card
    {
        $answer = is_array($card->answer_json) ? $card->answer_json : [];
        $nextAnswer = $this->answerWithOverrides($answer, $data);
        $text = $this->audioText($nextAnswer);
        $voiceId = $this->voiceId($nextAnswer);
        $snapshotFingerprint = $this->cardFingerprint($card);
        $oldGeneratedMedia = $this->generatedAnswerMedia($card, $answer);

        $this->generationRateLimiter->consume($card->ownerUserId());
        $generated = $this->persistGeneratedMedia->handle(
            userId: $card->ownerUserId(),
            bytes: $this->fishAudio->generate($text, $voiceId),
            mediaKind: 'audio',
            mimeType: 'audio/mpeg',
            extension: 'mp3',
        );

        try {
            $updated = DB::transaction(function () use ($card, $nextAnswer, $generated, $snapshotFingerprint, $oldGeneratedMedia): Card {
                $lockedCard = Card::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();

                if (! hash_equals($snapshotFingerprint, $this->cardFingerprint($lockedCard))) {
                    throw StudyCardAudioConflictException::cardChanged();
                }

                $nextAnswer['answerAudio'] = $generated->mediaRef;
                $lockedCard->answer_audio_source = 'generated';

                $this->updateCard->handle($lockedCard, UpdateCardData::fromInput(
                    frontText: $lockedCard->front_text,
                    backText: $lockedCard->back_text,
                    hasAnswerJson: true,
                    answerJson: $nextAnswer,
                ));
                $this->attachMedia->handle(AttachMediaToCardData::fromModels(
                    $lockedCard,
                    $generated->mediaAsset,
                ));
                if ($oldGeneratedMedia !== null && $oldGeneratedMedia->isNot($generated->mediaAsset)) {
                    $this->detachMedia->handle(DetachMediaFromCardData::fromModels(
                        $lockedCard,
                        $oldGeneratedMedia,
                    ));
                }

                return $lockedCard->fresh(['deck', 'mediaAssets']) ?? $lockedCard;
            });
        } catch (Throwable $exception) {
            $this->discardGeneratedMedia->handle($generated->mediaAsset);

            throw $exception;
        }

        if ($oldGeneratedMedia !== null) {
            $this->discardGeneratedMedia->handleIfUnreferenced($oldGeneratedMedia);
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     */
    private function answerWithOverrides(array $answer, RegenerateStudyCardAnswerAudioData $data): array
    {
        if ($data->hasVoiceId) {
            $answer['answerAudioVoiceId'] = $data->voiceId;
        }

        if ($data->hasTextOverride) {
            $answer['answerAudioTextOverride'] = $data->textOverride;
        }

        return $answer;
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function audioText(array $answer): string
    {
        foreach (['answerAudioTextOverride', 'restoredText', 'expression', 'sentenceJp', 'meaning'] as $key) {
            $value = $answer[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $value = trim($value);
                if (mb_strlen($value, 'UTF-8') > FishAudioSpeechGenerator::MAX_TEXT_LENGTH) {
                    throw StudyCardAudioValidationException::textTooLong(FishAudioSpeechGenerator::MAX_TEXT_LENGTH);
                }

                return $value;
            }
        }

        throw StudyCardAudioValidationException::missingText();
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function voiceId(array $answer): string
    {
        $voiceId = $answer['answerAudioVoiceId'] ?? StudyCardGenerationDefaults::VOICE_ID;

        if (! is_string($voiceId) || preg_match('/^fishaudio:[a-f0-9]{32}$/i', trim($voiceId)) !== 1) {
            throw StudyCardAudioValidationException::invalidVoice();
        }

        return trim($voiceId);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function generatedAnswerMedia(Card $card, array $answer): ?MediaAsset
    {
        $reference = $answer['answerAudio'] ?? null;
        if (! is_array($reference) || ($reference['source'] ?? null) !== 'generated') {
            return null;
        }

        $mediaId = $reference['id'] ?? null;
        if (! is_string($mediaId)) {
            return null;
        }

        return $card->mediaAssets()
            ->whereKey($mediaId)
            ->where('user_id', $card->ownerUserId())
            ->first();
    }

    private function cardFingerprint(Card $card): string
    {
        return hash('sha256', serialize([
            $card->front_text,
            $card->back_text,
            $card->prompt_json,
            $card->answer_json,
            $card->answer_audio_source,
            $card->updated_at?->toJSON(),
        ]));
    }
}
