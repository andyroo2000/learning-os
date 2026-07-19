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
use App\Domain\Study\Data\RegenerateStudyCardImageData;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Exceptions\StudyCardImageConflictException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Services\OpenAiStudyImageGenerator;
use App\Domain\Study\Support\StudyMediaGenerationRateLimiter;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RegenerateStudyCardImageAction
{
    public function __construct(
        private readonly OpenAiStudyImageGenerator $openAiImage,
        private readonly PersistGeneratedStudyMediaAction $persistGeneratedMedia,
        private readonly DiscardGeneratedStudyMediaAction $discardGeneratedMedia,
        private readonly UpdateCardAction $updateCard,
        private readonly AttachMediaToCardAction $attachMedia,
        private readonly DetachMediaFromCardAction $detachMedia,
        private readonly StudyMediaGenerationRateLimiter $generationRateLimiter,
    ) {}

    public function handle(Card $card, RegenerateStudyCardImageData $data): Card
    {
        $prompt = $this->promptPayload($card);
        $answer = $this->answerPayload($card);
        $snapshotFingerprint = $this->cardFingerprint($card);
        $oldGeneratedMedia = $this->generatedImageMedia($card, $prompt, $answer);

        $this->generationRateLimiter->consume($card->ownerUserId());
        $generated = $this->persistGeneratedMedia->handle(
            userId: $card->ownerUserId(),
            bytes: $this->openAiImage->generate($data->imagePrompt),
            mediaKind: 'image',
            mimeType: 'image/webp',
            extension: 'webp',
        );

        try {
            $updated = DB::transaction(function () use (
                $card,
                $data,
                $prompt,
                $answer,
                $snapshotFingerprint,
                $oldGeneratedMedia,
                $generated,
            ): Card {
                $lockedCard = Card::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();

                if (! hash_equals($snapshotFingerprint, $this->cardFingerprint($lockedCard))) {
                    throw StudyCardImageConflictException::cardChanged();
                }

                $nextPrompt = $prompt;
                $nextPrompt['cueImage'] = $this->usesPrompt($data->imagePlacement)
                    ? $generated->mediaRef
                    : null;
                $nextAnswer = $answer;
                $nextAnswer['answerImage'] = $this->usesAnswer($data->imagePlacement)
                    ? $generated->mediaRef
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
                    $generated->mediaAsset,
                ));

                foreach ($oldGeneratedMedia as $oldMedia) {
                    if ($oldMedia->is($generated->mediaAsset)) {
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
     * @return array<string, mixed>
     */
    private function promptPayload(Card $card): array
    {
        return is_array($card->prompt_json)
            ? $card->prompt_json
            : ['type' => 'text', 'text' => $card->front_text];
    }

    /**
     * @return array<string, mixed>
     */
    private function answerPayload(Card $card): array
    {
        return is_array($card->answer_json)
            ? $card->answer_json
            : ['type' => 'text', 'text' => $card->back_text];
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
    private function generatedImageMedia(Card $card, array $prompt, array $answer): Collection
    {
        $ids = collect([$prompt['cueImage'] ?? null, $answer['answerImage'] ?? null])
            ->filter(fn (mixed $reference): bool => is_array($reference)
                && ($reference['source'] ?? null) === StudyCardDraft::MEDIA_SOURCE_GENERATED
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
