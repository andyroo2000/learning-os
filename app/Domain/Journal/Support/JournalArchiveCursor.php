<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Models\JournalEntry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class JournalArchiveCursor
{
    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }
        $json = base64_decode($cursor, true);
        $data = $json === false ? null : json_decode($json, true);
        $validator = Validator::make(is_array($data) ? $data : [], [
            'entryDate' => ['required', 'date_format:Y-m-d'],
            'id' => ['required', 'ulid', 'lowercase'],
        ]);
        if ($validator->fails()) {
            throw ValidationException::withMessages(['cursor' => 'Invalid journal cursor.']);
        }

        return $validator->validated();
    }

    public static function encode(JournalEntry $entry): string
    {
        return base64_encode(json_encode([
            'entryDate' => $entry->entry_date->format('Y-m-d'),
            'id' => $entry->id,
        ], JSON_THROW_ON_ERROR));
    }
}
