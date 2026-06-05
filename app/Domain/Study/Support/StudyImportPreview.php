<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Models\StudyImportJob;

final class StudyImportPreview
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function empty(string $deckName = StudyImportJob::DEFAULT_DECK_NAME): array
    {
        return [
            'deck_name' => $deckName,
            'card_count' => 0,
            'note_count' => 0,
            'review_log_count' => 0,
            'media_reference_count' => 0,
            'skipped_media_count' => 0,
            'warnings' => [],
            'note_type_breakdown' => [],
        ];
    }
}
