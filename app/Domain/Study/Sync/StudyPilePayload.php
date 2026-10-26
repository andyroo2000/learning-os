<?php

namespace App\Domain\Study\Sync;

use App\Domain\Study\Models\StudyPile;
use App\Domain\Study\Models\StudyPileChapter;

final class StudyPilePayload
{
    public static function record(StudyPile|StudyPileChapter $record): array
    {
        $payload = [
            'id' => $record->id,
            'title' => $record->title,
            'source_reference' => $record->source_reference,
            'position' => $record->position,
            'is_active' => $record->is_active,
            'created_at' => $record->created_at?->toJSON(),
            'updated_at' => $record->updated_at?->toJSON(),
        ];
        if ($record instanceof StudyPileChapter) {
            $payload['pile_id'] = $record->pile_id;
        }

        return $payload;
    }

    public static function withChapters(StudyPile $pile): array
    {
        return [
            ...self::record($pile),
            'chapters' => $pile->chapters->map(self::record(...))->all(),
        ];
    }
}
