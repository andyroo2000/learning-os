<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

final class DeleteJournalEntry
{
    public function handle(int $userId, string $id, int $expectedVersion): void
    {
        $id = CanonicalUlid::normalize($id);
        Validator::make(compact('id', 'expectedVersion'), [
            'id' => ['required', 'ulid'],
            'expectedVersion' => ['integer', 'min:1', 'max:2147483647'],
        ])->validate();
        $ids = JournalWriteTransaction::run($userId, function () use ($userId, $id, $expectedVersion): array {
            $entry = JournalEntry::withTrashed()->lockForUpdate()->findOrFail($id);
            if ($entry->user_id !== $userId) {
                throw new ModelNotFoundException;
            }
            if (! $entry->trashed() && $entry->version !== $expectedVersion) {
                throw new JournalVersionConflict;
            }

            return $this->deleteConversation($entry);
        });
        foreach ($ids as $deletedId) {
            abort_unless(Storage::disk('local')->deleteDirectory('journal/'.$deletedId), 503, 'Audio cleanup failed. Please retry deleting this entry.');
        }
    }

    private function deleteConversation(JournalEntry $entry): array
    {
        // Retain tombstones so a retry also retries audio cleanup for every child.
        $entries = JournalEntry::withTrashed()->where('id', $entry->id)->orWhere('root_entry_id', $entry->id)->lockForUpdate()->get();
        foreach ($entries as $item) {
            if (! $item->trashed()) {
                $item->delete();
            }
        }

        return $entries->pluck('id')->all();
    }
}
