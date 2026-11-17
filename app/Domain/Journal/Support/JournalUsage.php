<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalClarification;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalReply;
use App\Support\Usage\UsageScope;
use Closure;

final class JournalUsage
{
    public static function entry(?JournalEntry $entry, string $operation, Closure $work): mixed
    {
        abort_if($entry === null, 404);

        return UsageScope::run($entry->user_id, $entry->id, $operation, $work);
    }

    public static function analysis(string $id, Closure $work): mixed
    {
        return self::entry(JournalAnalysis::findOrFail($id)->entry, 'journal.correction', $work);
    }

    public static function clarification(string $id, Closure $work): mixed
    {
        return self::entry(JournalClarification::findOrFail($id)->entry, 'journal.clarification', $work);
    }

    public static function reply(string $id, Closure $work): mixed
    {
        return self::entry(JournalReply::findOrFail($id)->analysis->entry, 'journal.reply', $work);
    }
}
