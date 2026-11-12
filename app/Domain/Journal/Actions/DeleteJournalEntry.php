<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        JournalWriteTransaction::run($userId, function () use ($userId, $id, $expectedVersion): void {
            $entry = JournalEntry::withTrashed()->lockForUpdate()->findOrFail($id);
            if ($entry->user_id !== $userId) {
                throw new ModelNotFoundException;
            }
            if ($entry->trashed()) {
                return;
            }
            if ($entry->version !== $expectedVersion) {
                throw new JournalVersionConflict;
            }
            $entry->delete();
        });
    }
}
