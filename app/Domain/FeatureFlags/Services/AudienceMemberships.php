<?php

namespace App\Domain\FeatureFlags\Services;

use Illuminate\Support\Facades\DB;

final class AudienceMemberships
{
    // Call only while holding the owner's row lock inside the write transaction.
    public function replace(string $table, array $owner, string $memberColumn, array $ids): void
    {
        DB::table($table)->where($owner)->delete();
        foreach (array_chunk($ids, 100) as $chunk) {
            DB::table($table)->insert(array_map(
                fn ($id) => [...$owner, $memberColumn => $id], $chunk,
            ));
        }
    }
}
