<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Enums\CardType;
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
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $prompt = is_array($card->prompt_json) ? $card->prompt_json : [];
        $answer = is_array($card->answer_json) ? $card->answer_json : [];
        $nextAnswer = $this->answerWithOverrides($answer, $data);
        $text = $this->audioText($nextAnswer);
        $voiceId = $this->voiceId($nextAnswer);
        $nextAnswer['answerAudioVoiceId'] = $voiceId;
        $snapshotFingerprint = $this->cardFingerprint($card);
        $syncPromptAudio = $this->isAudioRecognitionPrompt($card, $prompt);
        $oldGeneratedMedia = $this->generatedAudioMedia(
            $card,
            $prompt,
            $answer,
            $syncPromptAudio,
        );

        $this->generationRateLimiter->consume($card->ownerUserId());
        $generated = $this->persistGeneratedMedia->handle(
            userId: $card->ownerUserId(),
            bytes: $this->fishAudio->generate($text, $voiceId),
            mediaKind: 'audio',
            mimeType: 'audio/mpeg',
            extension: 'mp3',
        );

        try {
            $updated = DB::transaction(function () use (
                $card,
                $prompt,
                $nextAnswer,
                $generated,
                $snapshotFingerprint,
                $syncPromptAudio,
                $oldGeneratedMedia,
            ): Card {
                $lockedCard = Card::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();

                if (! hash_equals($snapshotFingerprint, $this->cardFingerprint($lockedCard))) {
                    throw StudyCardAudioConflictException::cardChanged();
                }

                $nextAnswer['answerAudio'] = $generated->mediaRef;
                $nextPrompt = $prompt;
                if ($syncPromptAudio) {
                    // Audio-recognition cards play cueAudio on the front, so both sides must
                    // advance together or regeneration leaves the prompt on retired media.
                    $nextPrompt['cueAudio'] = $generated->mediaRef;
                }
                $lockedCard->answer_audio_source = 'generated';

                $this->updateCard->handle($lockedCard, UpdateCardData::fromInput(
                    frontText: $lockedCard->front_text,
                    backText: $lockedCard->back_text,
                    hasFrontText: false,
                    hasBackText: false,
                    hasPromptJson: $syncPromptAudio,
                    promptJson: $nextPrompt,
                    hasAnswerJson: true,
                    answerJson: $nextAnswer,
                ));
                $this->attachMedia->handle(AttachMediaToCardData::fromModels(
                    $lockedCard,
                    $generated->mediaAsset,
                ));
                foreach ($oldGeneratedMedia as $oldMedia) {
                    if ($oldMedia->is($generated->mediaAsset)
                        || $this->payloadsReferenceAudioMedia($nextPrompt, $nextAnswer, $oldMedia)) {
                        continue;
                    }

                    $this->detachMedia->handle(DetachMediaFromCardData::fromModels(
                        $lockedCard,
                        $oldMedia,
                    ));
                }

                return $lockedCard->fresh(['deck', 'mediaAssets']) ?? $lockedCard;
            });
        } catch (Throwable $exception) {
            $this->discardGeneratedMedia->handle($generated->mediaAsset);

            throw $exception;
        }

        foreach ($oldGeneratedMedia as $oldMedia) {
            $this->discardGeneratedMedia->handleIfUnreferenced($oldMedia);
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

        if (! is_string($voiceId)) {
            throw StudyCardAudioValidationException::invalidVoice();
        }

        return StudyCardGenerationDefaults::normalizeVoiceId($voiceId)
            ?? throw StudyCardAudioValidationException::invalidVoice();
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function isAudioRecognitionPrompt(Card $card, array $prompt): bool
    {
        if ($card->card_type !== CardType::Recognition || ! is_array($prompt['cueAudio'] ?? null)) {
            return false;
        }

        foreach ([
            'cueText',
            'cueReading',
            'cueMeaning',
            'clozeText',
            'clozeDisplayText',
            'clozeAnswerText',
            'clozeHint',
            'clozeResolvedHint',
            'text',
        ] as $key) {
            $value = $prompt[$key] ?? null;
            if ((is_string($value) && trim($value) !== '')
                || (is_array($value) && $value !== [])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @param  array<string, mixed>  $answer
     * @return Collection<int, MediaAsset>
     */
    private function generatedAudioMedia(
        Card $card,
        array $prompt,
        array $answer,
        bool $syncPromptAudio,
    ): Collection {
        $references = [$answer['answerAudio'] ?? null];
        if ($syncPromptAudio) {
            $references[] = $prompt['cueAudio'] ?? null;
        }

        $ids = collect($references)
            ->filter(fn (mixed $reference): bool => is_array($reference)
                && ($reference['source'] ?? null) === 'generated'
                && is_string($reference['id'] ?? null)
                && Str::isUlid($reference['id']))
            ->map(fn (array $reference): string => CanonicalUlid::normalize($reference['id']))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return $card->mediaAssets()
            ->where('user_id', $card->ownerUserId())
            ->whereIn('media_assets.id', $ids->all())
            ->get();
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @param  array<string, mixed>  $answer
     */
    private function payloadsReferenceAudioMedia(
        array $prompt,
        array $answer,
        MediaAsset $media,
    ): bool {
        foreach ([$prompt['cueAudio'] ?? null, $answer['answerAudio'] ?? null] as $reference) {
            if (is_array($reference)
                && is_string($reference['id'] ?? null)
                && strtolower($reference['id']) === strtolower($media->id)) {
                return true;
            }
        }

        return false;
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
