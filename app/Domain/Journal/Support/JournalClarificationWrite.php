<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Models\JournalClarification;
use App\Domain\Journal\Models\JournalEntry;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class JournalClarificationWrite
{
    /** Lock owner, entry, then clarification; deleted entries/accounts become harmless no-ops. */
    public static function run(string $id, callable $change): mixed
    {
        $entry = JournalClarification::find($id)?->entry;
        if ($entry === null) {
            return null;
        }
        try {
            return JournalWriteTransaction::run($entry->user_id, function () use ($id, $entry, $change) {
                $lockedEntry = JournalEntry::lockForUpdate()->find($entry->id);
                $clarification = JournalClarification::lockForUpdate()->find($id);
                if ($lockedEntry === null || $clarification === null) {
                    return null;
                }

                return $change($clarification, $lockedEntry);
            });
        } catch (ModelNotFoundException) {
            // Account deletion can win the owner lock after the initial lookup.
            return null;
        }
    }
}
