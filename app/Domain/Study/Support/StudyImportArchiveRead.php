<?php

namespace App\Domain\Study\Support;

final readonly class StudyImportArchiveRead
{
    /**
     * @param  list<StudyImportArchiveCard>  $cards
     * @param  list<StudyImportArchiveReviewLog>  $reviewLogs
     * @param  array<string, StudyImportArchiveMediaEntry>  $mediaManifestByFilename
     */
    public function __construct(
        public string $deckName,
        public array $cards,
        public array $reviewLogs,
        public array $mediaManifestByFilename,
    ) {}

    public function cardCount(): int
    {
        return count($this->cards);
    }

    public function noteCount(): int
    {
        $noteIds = [];

        foreach ($this->cards as $card) {
            $noteIds[$card->sourceNoteId] = true;
        }

        return count($noteIds);
    }

    public function reviewLogCount(): int
    {
        return count($this->reviewLogs);
    }

    /**
     * @return list<string>
     */
    public function mediaReferences(): array
    {
        $mediaReferences = [];

        foreach ($this->cards as $card) {
            foreach ($card->mediaReferences() as $filename) {
                $mediaReferences[$filename] = true;
            }
        }

        return array_keys($mediaReferences);
    }

    /**
     * @return list<array{note_type_name: string, note_count: int, card_count: int}>
     */
    public function noteTypeBreakdown(): array
    {
        $breakdown = [];

        foreach ($this->cards as $card) {
            $noteTypeName = $card->sourceNoteTypeName !== '' ? $card->sourceNoteTypeName : 'Unknown';

            $breakdown[$noteTypeName] ??= [
                'note_type_name' => $noteTypeName,
                'note_ids' => [],
                'card_count' => 0,
            ];

            $breakdown[$noteTypeName]['note_ids'][$card->sourceNoteId] = true;
            $breakdown[$noteTypeName]['card_count']++;
        }

        return array_values(array_map(
            static fn (array $item): array => [
                'note_type_name' => $item['note_type_name'],
                'note_count' => count($item['note_ids']),
                'card_count' => $item['card_count'],
            ],
            $breakdown,
        ));
    }
}
