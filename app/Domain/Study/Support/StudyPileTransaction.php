<?php

namespace App\Domain\Study\Support;

use App\Support\Database\IntegrityConstraintViolation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class StudyPileTransaction
{
    public static function run(string $table, callable $write): mixed
    {
        try {
            return DB::transaction($write);
        } catch (QueryException $exception) {
            // Owner locks serialize same-user retries. A competing owner's insert
            // can still win the global ID; hide that collision after rollback.
            if (IntegrityConstraintViolation::matchesPrimaryKey($exception, $table)) {
                throw new ModelNotFoundException;
            }

            throw $exception;
        }
    }
}
