<?php

namespace App\Domain\Study\Support;

final readonly class StudyImportArchiveCard
{
    private const FIELD_SEPARATOR = "\x1f";

    public string $frontText;

    public string $backText;

    /** @var list<string> */
    public array $frontMediaReferences;

    /**
     * @param  array{front: string, back: string, front_media_references: list<string>}  $renderedText
     */
    public function __construct(
        public int $sourceCardId,
        public int $sourceNoteId,
        public int $sourceDeckId,
        public int $sourceNoteTypeId,
        public string $sourceNoteTypeName,
        public int $sourceTemplateOrdinal,
        array $renderedText,
        public string $noteFields,
        /** @var array<string, string|null>|null */
        public ?array $answerPayload = null,
    ) {
        $this->frontText = $renderedText['front'];
        $this->backText = $renderedText['back'];
        $this->frontMediaReferences = $renderedText['front_media_references'];
    }

    /**
     * @return list<string>
     */
    public function mediaReferences(): array
    {
        $mediaReferences = [];

        foreach (explode(self::FIELD_SEPARATOR, $this->noteFields) as $fieldValue) {
            foreach (StudyFieldMediaReferences::filenamesFromText($fieldValue) as $filename) {
                $mediaReferences[$filename] = true;
            }
        }

        return array_keys($mediaReferences);
    }
}
