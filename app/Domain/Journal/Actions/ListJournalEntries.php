<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalArchiveCursor;
use Illuminate\Support\Facades\Validator;

final class ListJournalEntries
{
    public function handle(int $userId, ?string $cursor = null, int $limit = 30): array
    {
        Validator::make(compact('cursor', 'limit'), [
            'cursor' => ['nullable', 'string', 'max:512'],
            'limit' => ['integer', 'min:1', 'max:50'],
        ])->validate();
        $position = JournalArchiveCursor::decode($cursor);
        $query = JournalEntry::where('user_id', $userId)->whereNull('root_entry_id');
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
}
