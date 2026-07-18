<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Data\UpdateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Services\FishAudioSpeechGenerator;
use App\Domain\Study\Support\StudyCardGenerationDefaults;
use App\Domain\Study\Support\StudyMediaGenerationRateLimiter;
use Throwable;

class GenerateStudyCardDraftPreviewAudioAction
{
    public function __construct(
        private readonly FishAudioSpeechGenerator $fishAudio,
        private readonly PersistGeneratedStudyMediaAction $persistGeneratedMedia,
        private readonly UpdateStudyCardDraftAction $updateDraft,
        private readonly DiscardGeneratedStudyMediaAction $discardGeneratedMedia,
        private readonly StudyMediaGenerationRateLimiter $generationRateLimiter,
    ) {}

    public function handle(StudyCardDraft $draft): StudyCardDraft
    {
        if ($draft->status === StudyManualCardDraftStatus::Generating) {
            throw StudyCardDraftConflictException::generatingCannotBeEdited();
        }

        $text = $this->audioText($draft);
        if ($text === null) {
            throw StudyCardDraftValidationException::missingPreviewAudioText();
        }

        if (mb_strlen($text, 'UTF-8') > FishAudioSpeechGenerator::MAX_TEXT_LENGTH) {
            throw StudyCardDraftValidationException::previewAudioTextTooLong(FishAudioSpeechGenerator::MAX_TEXT_LENGTH);
        }

        $configuredVoiceId = $this->answerString($draft, 'answerAudioVoiceId')
            ?? StudyCardGenerationDefaults::VOICE_ID;
        // Normalize provider input without rewriting draft fields from this pre-generation snapshot.
        $voiceId = StudyCardGenerationDefaults::normalizeVoiceId($configuredVoiceId);
        if ($voiceId === null) {
            throw StudyCardDraftValidationException::invalidPreviewAudioVoice();
        }

        $this->generationRateLimiter->consume($draft->user_id);
        $generated = $this->persistGeneratedMedia->handle(
            userId: $draft->user_id,
            bytes: $this->fishAudio->generate($text, $voiceId),
            mediaKind: 'audio',
            mimeType: 'audio/mpeg',
            extension: 'mp3',
        );
        $role = $draft->creation_kind === StudyCardCreationKind::AudioRecognition
            ? StudyCardAudioRole::Prompt
            : StudyCardAudioRole::Answer;

        try {
            return $this->updateDraft->handle($draft, UpdateStudyCardDraftData::fromInput(
                hasPreviewAudio: true,
                previewAudioJson: $generated->mediaRef,
                hasPreviewAudioRole: true,
                previewAudioRole: $role,
            ));
        } catch (Throwable $exception) {
            $this->discardGeneratedMedia->handle($generated->mediaAsset);

            throw $exception;
        }
    }

    private function audioText(StudyCardDraft $draft): ?string
    {
        $keys = $draft->creation_kind === StudyCardCreationKind::AudioRecognition
            ? ['answerAudioTextOverride', 'expression', 'expressionReading']
            : ['answerAudioTextOverride', 'restoredText', 'expression', 'sentenceJp', 'meaning'];

        foreach ($keys as $key) {
            $value = $this->answerString($draft, $key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function answerString(StudyCardDraft $draft, string $key): ?string
    {
        $value = $draft->answer_json[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
