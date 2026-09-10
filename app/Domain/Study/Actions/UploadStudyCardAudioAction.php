<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Exceptions\StudyCardAudioConflictException;
use App\Domain\Study\Exceptions\StudyCardAudioValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class UploadStudyCardAudioAction
{
    public function __construct(
        private readonly PersistUploadedStudyAudioAction $persistUploadedAudio,
        private readonly UpdateCardAction $updateCard,
        private readonly ReplaceStudyCardAudioMediaAction $media,
    ) {}

    public function handle(Card $card, UploadedFile $audio): Card
    {
        if ($card->card_type !== CardType::Recognition) {
            throw StudyCardAudioValidationException::recognitionCardRequired();
        }

        $prompt = $this->payload($card->prompt_json, $card->front_text);
        $answer = $this->payload($card->answer_json, $card->back_text);
        $snapshotFingerprint = $this->cardFingerprint($card);
        $oldManagedMedia = $this->managedAudioMedia($card, $prompt, $answer);
        $uploaded = $this->persistUploadedAudio->handle($card->ownerUserId(), $audio);

        try {
            $updated = DB::transaction(function () use (
                $card,
                $prompt,
                $answer,
                $snapshotFingerprint,
                $oldManagedMedia,
                $uploaded,
            ): Card {
                $lockedCard = Card::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();

                if (! hash_equals($snapshotFingerprint, $this->cardFingerprint($lockedCard))) {
                    throw StudyCardAudioConflictException::cardChangedDuringUpload();
                }

                $nextPrompt = array_intersect_key($prompt, ['cueImage' => true]);
                $nextPrompt['cueAudio'] = $uploaded->mediaRef;
                $nextAnswer = $answer;
                $nextAnswer['answerAudio'] = $uploaded->mediaRef;

                $this->updateCard->handle($lockedCard, UpdateCardData::fromInput(
                    frontText: $lockedCard->front_text,
                    backText: $lockedCard->back_text,
                    hasFrontText: false,
                    hasBackText: false,
                    hasPromptJson: true,
                    promptJson: $nextPrompt,
                    hasAnswerJson: true,
                    answerJson: $nextAnswer,
                    hasAnswerAudioSource: true,
                    answerAudioSource: StudyCardDraft::MEDIA_SOURCE_IMPORTED,
                ));
                $this->media->attachReplacement($lockedCard, $uploaded->mediaAsset, $oldManagedMedia);

                return $lockedCard->fresh(['deck', 'mediaAssets']) ?? $lockedCard;
            });
        } catch (Throwable $exception) {
            $this->media->discardFailedUpload($uploaded->mediaAsset);

            throw $exception;
        }

        $this->media->discardUnreferenced($oldManagedMedia);

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(mixed $payload, ?string $fallback): array
    {
        return is_array($payload) ? $payload : ['type' => 'text', 'text' => $fallback];
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @param  array<string, mixed>  $answer
     * @return Collection<int, MediaAsset>
     */
    private function managedAudioMedia(Card $card, array $prompt, array $answer): Collection
    {
        $managedSources = [
            StudyCardDraft::MEDIA_SOURCE_GENERATED,
            StudyCardDraft::MEDIA_SOURCE_IMPORTED,
        ];
        $ids = collect([$prompt['cueAudio'] ?? null, $answer['answerAudio'] ?? null])
            ->filter(fn (mixed $reference): bool => is_array($reference)
                && in_array($reference['source'] ?? null, $managedSources, true)
                && is_string($reference['id'] ?? null)
                && Str::isUlid($reference['id']))
            ->map(fn (array $reference): string => CanonicalUlid::normalize($reference['id']))
            ->unique()
            ->values();

        return $ids->isEmpty()
            ? collect()
            : $card->mediaAssets()
                ->where('user_id', $card->ownerUserId())
                ->whereIn('media_assets.id', $ids->all())
                ->get();
    }

    private function cardFingerprint(Card $card): string
    {
        return hash('sha256', serialize([
            $card->front_text,
            $card->back_text,
            $card->card_type?->value,
            $card->prompt_json,
            $card->answer_json,
            $card->answer_audio_source,
            $card->updated_at?->toJSON(),
        ]));
    }
}
