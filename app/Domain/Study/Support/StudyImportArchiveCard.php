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
        public string $noteFields,
    ) {}

    /**
     * @return list<string>
     */
    public function mediaReferences(): array
    {
        $mediaReferences = [];

        foreach (explode(self::FIELD_SEPARATOR, $this->noteFields) as $fieldValue) {
            foreach ($this->extractMediaReferences($fieldValue) as $filename) {
                $mediaReferences[$filename] = true;
            }
        }

        return array_keys($mediaReferences);
    }

    /**
     * @return list<string>
     */
    private function extractMediaReferences(string $value): array
    {
        $references = [];

        preg_match_all('/\[sound:([^\]\r\n]+)\]/i', $value, $soundMatches);
        foreach ($soundMatches[1] ?? [] as $filename) {
            $references[] = trim(html_entity_decode($filename, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\']?)([^"\'\s>]+)\1/i', $value, $imageMatches);
        foreach ($imageMatches[2] ?? [] as $filename) {
            $references[] = trim(html_entity_decode($filename, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return array_values(array_filter(
            $references,
            static fn (string $filename): bool => $filename !== '',
        ));
    }
}
