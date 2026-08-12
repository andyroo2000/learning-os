<?php

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class UserHierarchyLock
{
    /**
     * Serialize user-owned hierarchy mutations before locking descendants.
     *
     * PostgreSQL's NO KEY UPDATE mode still conflicts with account deletion and other
     * hierarchy writers, while allowing sync-feed foreign-key checks to take KEY SHARE.
     * Other supported drivers fall back to their portable exclusive row lock.
     *
     * @param  class-string<Model>  $missingModel
     */
    public static function acquireOrFail(int $userId, string $missingModel, string|int $missingId): void
    {
        $query = DB::table('users')->where('id', $userId);

        if (DB::connection()->getDriverName() === 'pgsql') {
            $query->lock('for no key update');
        } else {
            $query->lockForUpdate();
        }

        if ($query->value('id') === null) {
            throw (new ModelNotFoundException)->setModel($missingModel, [$missingId]);
        }
    }
}
