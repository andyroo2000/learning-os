<?php

namespace App\Domain\Study\Support;

final readonly class StudyImportArchiveCard
{
    private const FIELD_SEPARATOR = "\x1f";

    public function __construct(
        public int $sourceCardId,
        public int $sourceNoteId,
        public int $sourceDeckId,
        public int $sourceNoteTypeId,
        public string $sourceNoteTypeName,
        public int $sourceTemplateOrdinal,
        public string $frontText,
        public string $backText,
        public string $noteFields,
    ) {}

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
