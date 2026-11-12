<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Models\JournalEntry;
use Illuminate\Support\Facades\Storage;

final class DeleteJournalAccountAudio
{
    // The caller holds the user row lock also used when publishing audio.
    public function handle(int $userId): void
    {
        foreach (JournalEntry::withTrashed()->where('user_id', $userId)->select('id')->lazyById(100) as $entry) {
            abort_unless(Storage::disk('local')->deleteDirectory('journal/'.$entry->id), 503,
                'Journal audio cleanup failed. Please retry deleting your account.');
        }
    }
}
