<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Data\JournalReplyAttempt;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalReply;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class JournalReplyMutation
{
    public static function run(JournalReplyAttempt $attempt, callable $change): mixed
    {
        $entry = JournalReply::find($attempt->replyId)?->analysis?->entry;
        if ($entry === null) {
            return null;
        }
        try {
            return JournalWriteTransaction::run($entry->user_id, function () use ($attempt, $entry, $change) {
                $locked = JournalEntry::lockForUpdate()->find($entry->id);
                $reply = JournalReply::find($attempt->replyId);
                if ($locked === null || $reply === null) {
                    return null;
                }
                $analysis = JournalAnalysis::lockForUpdate()->findOrFail($reply->analysis_id);
                $reply = JournalReply::lockForUpdate()->findOrFail($reply->id);

                return $change($reply, $analysis, $locked);
            });
        } catch (ModelNotFoundException) {
            return null;
        }
    }
}
