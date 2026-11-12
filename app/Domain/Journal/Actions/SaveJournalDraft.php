<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalDraftInput;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;

final class SaveJournalDraft
{
    public function handle(int $userId, string $id, array $input): JournalEntry
    {
        $id = CanonicalUlid::normalize($id);
        Validator::make(['id' => $id], ['id' => ['required', 'ulid']])->validate();
        $input = JournalDraftInput::validated($input);

        return JournalWriteTransaction::run($userId, function () use ($userId, $id, $input): JournalEntry {
            $entry = JournalEntry::withTrashed()->lockForUpdate()->find($id);
            if ($entry?->trashed()) {
                throw new ModelNotFoundException;
            }
            if ($entry !== null && $entry->user_id !== $userId) {
                throw new ModelNotFoundException;
            }
            $entry ??= $this->newEntry($userId, $id);

            return $this->persist($entry, $input);
        });
    }

    private function newEntry(int $userId, string $id): JournalEntry
    {
        $entry = new JournalEntry;
        $entry->id = $id;
        $entry->user_id = $userId;

        return $entry;
    }

    private function persist(JournalEntry $entry, array $input): JournalEntry
    {
        $entry->title = $input['title'];
        $entry->body = $input['body'];
        $entry->entry_date = $input['entryDate'];
        // An exact retry succeeds without advancing the version or timestamp.
        if ($entry->exists && ! $entry->isDirty(['title', 'body', 'entry_date'])) {
            return $entry;
        }
        $currentVersion = $entry->exists ? $entry->version : 0;
        if ((int) $input['expectedVersion'] !== $currentVersion) {
            throw new JournalVersionConflict;
        }
        $entry->version = $currentVersion + 1;
        $entry->save();

        return $entry;
    }
}
