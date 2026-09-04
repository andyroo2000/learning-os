<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Vocabulary\Support\VocabVariantMetadataInput;

final class StudyCardDraftUpdateInputValidator
{
    private function __construct() {}

    /** @param array{hasPrompt: bool, promptJson: ?array, hasAnswer: bool, answerJson: ?array} $input */
    public static function assertPayloads(array $input): void
    {
        if ($input['hasPrompt'] !== $input['hasAnswer']) {
            throw StudyCardDraftValidationException::invalidPayloads();
        }

        if (! $input['hasPrompt']) {
            return;
        }

        if ($input['promptJson'] === null || $input['answerJson'] === null) {
            throw StudyCardDraftValidationException::invalidPayloads();
        }

        StudyCardPayloadShapeValidator::assertDraftPayloadsAreValid($input['promptJson'], $input['answerJson']);
    }

    /** @param array{isPresent: bool, mediaReference: ?array} $input */
    public static function assertPreviewAudioWhenPresent(array $input): void
    {
        self::assertMediaReferenceWhenPresent($input, 'audio');
    }

    /** @param array{isPresent: bool, mediaReference: ?array} $input */
    public static function assertPreviewImageWhenPresent(array $input): void
    {
        self::assertMediaReferenceWhenPresent($input, 'image');
    }

    /** @param array{isPresent: bool, variantStage: ?int} $input */
    public static function assertVariantStageWhenPresent(array $input): void
    {
        if ($input['isPresent']) {
            VocabVariantMetadataInput::assertValidStage(
                $input['variantStage'],
                'Study variant stage must be between 1 and 65535.',
            );
        }
    }

    /** @param array{isPresent: bool, mediaReference: ?array} $input */
    private static function assertMediaReferenceWhenPresent(array $input, string $expectedKind): void
    {
        if (! $input['isPresent'] || $input['mediaReference'] === null) {
            return;
        }

        self::assertAllowedKeys($input['mediaReference']);
        self::assertFilename($input['mediaReference']['filename'] ?? null);
        self::assertSource($input['mediaReference']['source'] ?? null);
        self::assertMediaKind($input['mediaReference']['mediaKind'] ?? null, $expectedKind);
        self::assertOptionalStrings($input['mediaReference']);
    }

    private static function assertAllowedKeys(array $mediaReference): void
    {
        foreach (array_keys($mediaReference) as $key) {
            if (! is_string($key) || ! in_array($key, StudyCardDraft::MEDIA_REF_ALLOWED_KEYS, true)) {
                throw StudyCardDraftValidationException::invalidPayloads();
            }
        }
    }

    private static function assertFilename(mixed $filename): void
    {
        if (! is_string($filename) || trim($filename) === '') {
            throw StudyCardDraftValidationException::invalidPayloads();
        }
    }

    private static function assertSource(mixed $source): void
    {
        if (! is_string($source) || ! in_array($source, StudyCardDraft::MEDIA_SOURCES, true)) {
            throw StudyCardDraftValidationException::invalidPayloads();
        }
    }

    private static function assertMediaKind(mixed $mediaKind, string $expectedKind): void
    {
        if ($mediaKind !== $expectedKind) {
            throw StudyCardDraftValidationException::invalidPayloads();
        }
    }

    private static function assertOptionalStrings(array $mediaReference): void
    {
        foreach (['id', 'url'] as $key) {
            self::assertOptionalString($mediaReference, $key);
        }
    }

    private static function assertOptionalString(array $mediaReference, string $key): void
    {
        if (! array_key_exists($key, $mediaReference)) {
            return;
        }

        $value = $mediaReference[$key];

        if ($value !== null && ! is_string($value)) {
            throw StudyCardDraftValidationException::invalidPayloads();
        }
    }
}
