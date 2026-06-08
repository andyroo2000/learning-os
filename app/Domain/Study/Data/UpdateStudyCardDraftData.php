<?php

namespace App\Domain\Study\Data;

use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardPayloadShapeValidator;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Domain\Vocabulary\Support\VocabVariantMetadataInput;
use DateTimeInterface;

final readonly class UpdateStudyCardDraftData
{
    private function __construct(
        public bool $hasPrompt,
        public ?array $promptJson,
        public bool $hasAnswer,
        public ?array $answerJson,
        public bool $hasImagePlacement,
        public ?StudyCardImagePlacement $imagePlacement,
        public bool $hasImagePrompt,
        public ?string $imagePrompt,
        public bool $hasPreviewAudio,
        public ?array $previewAudioJson,
        public bool $hasPreviewAudioRole,
        public ?StudyCardAudioRole $previewAudioRole,
        public bool $hasPreviewImage,
        public ?array $previewImageJson,
        public bool $hasVariantGroupId,
        public ?string $variantGroupId,
        public bool $hasVariantSentenceId,
        public ?string $variantSentenceId,
        public bool $hasVariantKind,
        public ?VocabVariantKind $variantKind,
        public bool $hasVariantStage,
        public ?int $variantStage,
        public bool $hasVariantStatus,
        public ?VocabVariantStatus $variantStatus,
        public bool $hasVariantUnlockedAt,
        public ?DateTimeInterface $variantUnlockedAt,
    ) {}

    public static function fromInput(
        bool $hasPrompt = false,
        ?array $promptJson = null,
        bool $hasAnswer = false,
        ?array $answerJson = null,
        bool $hasImagePlacement = false,
        StudyCardImagePlacement|string|null $imagePlacement = null,
        bool $hasImagePrompt = false,
        ?string $imagePrompt = null,
        bool $hasPreviewAudio = false,
        ?array $previewAudioJson = null,
        bool $hasPreviewAudioRole = false,
        StudyCardAudioRole|string|null $previewAudioRole = null,
        bool $hasPreviewImage = false,
        ?array $previewImageJson = null,
        bool $hasVariantGroupId = false,
        ?string $variantGroupId = null,
        bool $hasVariantSentenceId = false,
        ?string $variantSentenceId = null,
        bool $hasVariantKind = false,
        VocabVariantKind|string|null $variantKind = null,
        bool $hasVariantStage = false,
        ?int $variantStage = null,
        bool $hasVariantStatus = false,
        VocabVariantStatus|string|null $variantStatus = null,
        bool $hasVariantUnlockedAt = false,
        ?DateTimeInterface $variantUnlockedAt = null,
    ): self {
        if ($hasPrompt !== $hasAnswer) {
            throw StudyCardDraftValidationException::invalidPayloads();
        }

        if ($hasPrompt && $hasAnswer) {
            if ($promptJson === null || $answerJson === null) {
                throw StudyCardDraftValidationException::invalidPayloads();
            }

            self::validatePayloadShape($promptJson, $answerJson);
        }

        if ($hasPreviewAudio) {
            self::validateNullableMediaRef($previewAudioJson, 'audio');
        }

        if ($hasPreviewImage) {
            self::validateNullableMediaRef($previewImageJson, 'image');
        }

        if ($hasVariantStage) {
            VocabVariantMetadataInput::assertValidStage(
                $variantStage,
                'Study variant stage must be between 1 and 65535.',
            );
        }

        return new self(
            hasPrompt: $hasPrompt,
            promptJson: $promptJson,
            hasAnswer: $hasAnswer,
            answerJson: $answerJson,
            hasImagePlacement: $hasImagePlacement,
            imagePlacement: $hasImagePlacement ? self::imagePlacementFromInput($imagePlacement) : null,
            hasImagePrompt: $hasImagePrompt,
            imagePrompt: $hasImagePrompt ? self::nullableTrimmedString($imagePrompt) : null,
            hasPreviewAudio: $hasPreviewAudio,
            previewAudioJson: $hasPreviewAudio ? $previewAudioJson : null,
            hasPreviewAudioRole: $hasPreviewAudioRole,
            previewAudioRole: $hasPreviewAudioRole ? self::previewAudioRoleFromInput($previewAudioRole) : null,
            hasPreviewImage: $hasPreviewImage,
            previewImageJson: $hasPreviewImage ? $previewImageJson : null,
            hasVariantGroupId: $hasVariantGroupId,
            variantGroupId: $hasVariantGroupId ? VocabVariantMetadataInput::nullableId(
                $variantGroupId,
                'Study variant IDs must be 64 characters or fewer.',
            ) : null,
            hasVariantSentenceId: $hasVariantSentenceId,
            variantSentenceId: $hasVariantSentenceId ? VocabVariantMetadataInput::nullableId(
                $variantSentenceId,
                'Study variant IDs must be 64 characters or fewer.',
            ) : null,
            hasVariantKind: $hasVariantKind,
            variantKind: $hasVariantKind ? VocabVariantMetadataInput::kindFromInput($variantKind) : null,
            hasVariantStage: $hasVariantStage,
            variantStage: $hasVariantStage ? $variantStage : null,
            hasVariantStatus: $hasVariantStatus,
            variantStatus: $hasVariantStatus ? VocabVariantMetadataInput::statusFromInput($variantStatus) : null,
            hasVariantUnlockedAt: $hasVariantUnlockedAt,
            variantUnlockedAt: $hasVariantUnlockedAt ? VocabVariantMetadataInput::normalizedTimestamp($variantUnlockedAt) : null,
        );
    }

    public function hasAnyField(): bool
    {
        return $this->hasPrompt
            || $this->hasAnswer
            || $this->hasImagePlacement
            || $this->hasImagePrompt
            || $this->hasPreviewAudio
            || $this->hasPreviewAudioRole
            || $this->hasPreviewImage
            || $this->hasVariantGroupId
            || $this->hasVariantSentenceId
            || $this->hasVariantKind
            || $this->hasVariantStage
            || $this->hasVariantStatus
            || $this->hasVariantUnlockedAt;
    }

    private static function imagePlacementFromInput(StudyCardImagePlacement|string|null $imagePlacement): StudyCardImagePlacement
    {
        if ($imagePlacement instanceof StudyCardImagePlacement) {
            return $imagePlacement;
        }

        if ($imagePlacement === null) {
            // In a sparse PATCH, explicit null clears placement back to the stored "none" state.
            return StudyCardImagePlacement::None;
        }

        return StudyCardImagePlacement::from(strtolower(trim($imagePlacement)));
    }

    private static function previewAudioRoleFromInput(StudyCardAudioRole|string|null $previewAudioRole): ?StudyCardAudioRole
    {
        if ($previewAudioRole instanceof StudyCardAudioRole) {
            return $previewAudioRole;
        }

        if ($previewAudioRole === null) {
            return null;
        }

        return StudyCardAudioRole::from(strtolower(trim($previewAudioRole)));
    }

    private static function nullableTrimmedString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed, 'UTF-8') > StudyCardDraft::MAX_IMAGE_PROMPT_LENGTH) {
            throw StudyCardDraftValidationException::imagePromptTooLong(StudyCardDraft::MAX_IMAGE_PROMPT_LENGTH);
        }

        return $trimmed;
    }

    private static function validatePayloadShape(array $promptJson, array $answerJson): void
    {
        StudyCardPayloadShapeValidator::assertDraftPayloadsAreValid($promptJson, $answerJson);
    }

    private static function validateNullableMediaRef(?array $mediaRef, string $mediaKind): void
    {
        if ($mediaRef === null) {
            return;
        }

        foreach (array_keys($mediaRef) as $key) {
            if (! is_string($key) || ! in_array($key, StudyCardDraft::MEDIA_REF_ALLOWED_KEYS, true)) {
                throw StudyCardDraftValidationException::invalidPayloads();
            }
        }

        $filename = $mediaRef['filename'] ?? null;
        $source = $mediaRef['source'] ?? null;

        if (! is_string($filename) || trim($filename) === '') {
            throw StudyCardDraftValidationException::invalidPayloads();
        }

        if (! is_string($source) || ! in_array($source, StudyCardDraft::MEDIA_SOURCES, true)) {
            throw StudyCardDraftValidationException::invalidPayloads();
        }

        if (($mediaRef['mediaKind'] ?? null) !== $mediaKind) {
            throw StudyCardDraftValidationException::invalidPayloads();
        }

        foreach (['id', 'url'] as $optionalStringKey) {
            if (array_key_exists($optionalStringKey, $mediaRef)
                && $mediaRef[$optionalStringKey] !== null
                && ! is_string($mediaRef[$optionalStringKey])) {
                throw StudyCardDraftValidationException::invalidPayloads();
            }
        }
    }
}
