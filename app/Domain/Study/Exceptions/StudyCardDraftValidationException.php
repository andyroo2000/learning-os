<?php

namespace App\Domain\Study\Exceptions;

use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use RuntimeException;

class StudyCardDraftValidationException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $field,
    ) {
        parent::__construct($message);
    }

    public static function cardTypeMustMatchCreationKind(): self
    {
        return new self('cardType must match creationKind.', 'cardType');
    }

    public static function imagePromptTooLong(int $maxLength): self
    {
        return new self("imagePrompt must be {$maxLength} characters or fewer.", 'imagePrompt');
    }

    public static function invalidCreationKind(): self
    {
        return new self('creationKind must be one of: '.implode(', ', StudyCardCreationKind::values()).'.', 'creationKind');
    }

    public static function invalidImagePlacement(): self
    {
        return new self('imagePlacement must be one of: '.implode(', ', StudyCardImagePlacement::values()).'.', 'imagePlacement');
    }

    public static function invalidPayloads(): self
    {
        return new self('study card payloads contain invalid content.', 'payloads');
    }

    public static function invalidPreviewAudioRole(): self
    {
        return new self('previewAudioRole must be one of: '.implode(', ', StudyCardAudioRole::values()).'.', 'previewAudioRole');
    }

    public static function previewAudioRoleRequiresAudio(): self
    {
        return new self('previewAudioRole requires previewAudio.', 'previewAudioRole');
    }

    public static function missingPreviewAudioText(): self
    {
        return new self('The draft answer has no text available for audio generation.', 'answer');
    }

    public static function previewAudioTextTooLong(int $maxLength): self
    {
        return new self("Preview audio text must be {$maxLength} characters or fewer.", 'answer');
    }

    public static function invalidPreviewAudioVoice(): self
    {
        return new self('answer.answerAudioVoiceId must be a Fish Audio voice ID.', 'answer.answerAudioVoiceId');
    }

    public static function missingPreviewImagePrompt(): self
    {
        return new self('imagePrompt is required to generate a preview image.', 'imagePrompt');
    }

    public static function previewImageRequiresPlacement(): self
    {
        return new self('imagePlacement must not be none when generating a preview image.', 'imagePlacement');
    }

    public static function payloadsTooLarge(int $maxKilobytes): self
    {
        return new self("study card payloads must be {$maxKilobytes} KB or smaller.", 'payloads');
    }

    public static function promptTooDeep(int $maxDepth): self
    {
        return new self("prompt must be {$maxDepth} levels deep or fewer.", 'prompt');
    }

    public static function answerTooDeep(int $maxDepth): self
    {
        return new self("answer must be {$maxDepth} levels deep or fewer.", 'answer');
    }

    public function field(): string
    {
        return $this->field;
    }
}
