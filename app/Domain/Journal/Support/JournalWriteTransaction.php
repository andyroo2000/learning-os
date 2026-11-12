<?php

namespace App\Domain\Journal\Support;

use App\Models\User;
use App\Support\Database\IntegrityConstraintViolation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class JournalWriteTransaction
{
    public static function run(int $userId, callable $write): mixed
    {
        try {
            return DB::transaction(function () use ($userId, $write) {
                // Serialize same-owner creates as well as edits, including offline retries.
                User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

                return $write();
            });
        } catch (QueryException $exception) {
            // A different owner can win a global ID collision; hide it after rollback.
            if (IntegrityConstraintViolation::matchesPrimaryKey($exception, 'journal_entries')) {
                throw new ModelNotFoundException;
            }

            throw $exception;
        }
    }
}
