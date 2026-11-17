<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalArchiveCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

final class ListJournalEntries
{
    public function handle(int $userId, ?string $cursor = null, int $limit = 30, bool $unfinished = false): array
    {
        Validator::make(compact('cursor', 'limit'), [
            'cursor' => ['nullable', 'string', 'max:512'],
            'limit' => ['integer', 'min:1', 'max:50'],
        ])->validate();
        $position = JournalArchiveCursor::decode($cursor);
        $query = JournalEntry::where('user_id', $userId)->whereNull('root_entry_id');
        if ($unfinished) {
            $this->unfinished($query);
        }
        if ($position !== null) {
            $query->where(function ($query) use ($position): void {
                $query->where('entry_date', '<', $position['entryDate'])
                    ->orWhere(function ($query) use ($position): void {
                        $query->where('entry_date', $position['entryDate'])->where('id', '<', $position['id']);
                    });
            });
        }
        $entries = $query->orderByDesc('entry_date')->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $entries->count() > $limit;
        $page = $entries->take($limit)->values();

        return [
            'entries' => $page,
            'nextCursor' => $hasMore ? JournalArchiveCursor::encode($page->last()) : null,
        ];
    }

    private function unfinished(Builder $query): void
    {
        $spaces = [' ', "\t", "\n", "\r", "\v", "\f", "\u{00a0}", "\u{3000}"];
        $nonblank = array_reduce($spaces, fn ($sql) => "REPLACE($sql, ?, '')", 'body');
        $query->whereRaw("$nonblank <> ''", $spaces)->whereDoesntHave('latestAnalysis', function (Builder $analysis): void {
            // Match strict source-body equality, including case and trailing spaces on MySQL.
            if ($analysis->getConnection()->getDriverName() === 'mysql') {
                $analysis->whereRaw('CAST(journal_analyses.source_body AS BINARY) = CAST(journal_entries.body AS BINARY)');
            } else {
                $analysis->whereColumn('journal_analyses.source_body', 'journal_entries.body');
            }
            $analysis->whereIn('status', ['queued', 'processing', 'ready']);
        });
    }
}
