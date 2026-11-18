<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

final class JournalSubmission
{
    /** Called inside the submission's owner/entry transaction; retries never add another event. */
    public static function entry(JournalEntry $entry): void
    {
        self::record(['source_key' => 'entry:'.$entry->id, 'user_id' => $entry->user_id,
            'root_entry_id' => $entry->root_entry_id ?? $entry->id,
            'kind' => $entry->root_entry_id === null ? 'original' : 'followup',
            'characters' => mb_strlen($entry->body), 'submitted_at' => now('UTC')]);
    }

    /** @param array{source_key: string, user_id: int, root_entry_id: string, kind: string, characters: int, submitted_at: \DateTimeInterface|string} $event */
    public static function record(array $event): void
    {
        DB::table('journal_submission_events')->insertOrIgnore($event);
    }
}
