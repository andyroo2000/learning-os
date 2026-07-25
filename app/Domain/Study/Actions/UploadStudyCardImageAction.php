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
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Exceptions\StudyCardImageConflictException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class UploadStudyCardImageAction
{
    public function __construct(
        private readonly PersistUploadedStudyImageAction $persistUploadedImage,
        private readonly DiscardGeneratedStudyMediaAction $discardMedia,
        private readonly UpdateCardAction $updateCard,
        private readonly AttachMediaToCardAction $attachMedia,
        private readonly DetachMediaFromCardAction $detachMedia,
    ) {}

    public function handle(
        Card $card,
        UploadedFile $image,
        StudyCardImagePlacement $placement,
    ): Card {
        $prompt = $this->payload($card->prompt_json, $card->front_text);
        $answer = $this->payload($card->answer_json, $card->back_text);
        $snapshotFingerprint = $this->cardFingerprint($card);
        $oldManagedMedia = $this->managedImageMedia($card, $prompt, $answer);
        $uploaded = $this->persistUploadedImage->handle($card->ownerUserId(), $image);

        try {
            $updated = DB::transaction(function () use (
                $card,
                $placement,
                $prompt,
                $answer,
                $snapshotFingerprint,
                $oldManagedMedia,
                $uploaded,
            ): Card {
                $lockedCard = Card::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();

                if (! hash_equals($snapshotFingerprint, $this->cardFingerprint($lockedCard))) {
                    throw StudyCardImageConflictException::cardChanged();
                }

                $nextPrompt = $prompt;
                $nextPrompt['cueImage'] = $this->usesPrompt($placement)
                    ? $uploaded->mediaRef
                    : null;
                $nextAnswer = $answer;
                $nextAnswer['answerImage'] = $this->usesAnswer($placement)
                    ? $uploaded->mediaRef
                    : null;

                $this->updateCard->handle($lockedCard, UpdateCardData::fromInput(
                    frontText: $lockedCard->front_text,
                    backText: $lockedCard->back_text,
                    hasPromptJson: true,
                    promptJson: $nextPrompt,
                    hasAnswerJson: true,
                    answerJson: $nextAnswer,
                ));
                $this->attachMedia->handle(AttachMediaToCardData::fromModels(
                    $lockedCard,
                    $uploaded->mediaAsset,
                ));

                foreach ($oldManagedMedia as $oldMedia) {
                    $this->detachMedia->handle(DetachMediaFromCardData::fromModels(
                        $lockedCard,
                        $oldMedia,
                    ));
                }

                return $lockedCard->fresh(['deck', 'mediaAssets']) ?? $lockedCard;
            });
        } catch (Throwable $exception) {
            $this->discardMedia->handle($uploaded->mediaAsset);

            throw $exception;
        }

        foreach ($oldManagedMedia as $oldMedia) {
            $this->discardMedia->handleIfUnreferenced($oldMedia);
        }

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(mixed $payload, ?string $fallback): array
    {
        return is_array($payload) ? $payload : ['type' => 'text', 'text' => $fallback];
    }

    private function usesPrompt(StudyCardImagePlacement $placement): bool
    {
        return in_array($placement, [
            StudyCardImagePlacement::Prompt,
            StudyCardImagePlacement::Both,
        ], true);
    }

    private function usesAnswer(StudyCardImagePlacement $placement): bool
    {
        return in_array($placement, [
            StudyCardImagePlacement::Answer,
            StudyCardImagePlacement::Both,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @param  array<string, mixed>  $answer
     * @return Collection<int, MediaAsset>
     */
    private function managedImageMedia(Card $card, array $prompt, array $answer): Collection
    {
        $managedSources = [
            StudyCardDraft::MEDIA_SOURCE_GENERATED,
            StudyCardDraft::MEDIA_SOURCE_IMPORTED_IMAGE,
        ];
        $ids = collect([$prompt['cueImage'] ?? null, $answer['answerImage'] ?? null])
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
            $card->prompt_json,
            $card->answer_json,
            $card->updated_at?->toJSON(),
        ]));
    }
}
