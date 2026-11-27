<?php

namespace App\Domain\FeatureFlags\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AudienceMemberships
{
    // Hold referenced rows until commit so concurrent deletions cannot invalidate a save.
    public function lockReferences(array $input): void
    {
        $this->lockIds('users', $input['userIds'], 'userIds');
        $this->lockIds('feature_access_groups', $input['groupIds'] ?? [], 'groupIds');
    }

    private function lockIds(string $table, array $ids, string $field): void
    {
        $found = DB::table($table)->whereIn('id', $ids)->orderBy('id')->sharedLock()->pluck('id');
        if ($found->count() !== count($ids)) {
            throw ValidationException::withMessages([$field => 'A selected user or group no longer exists. Reload before saving.']);
        }
    }

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
