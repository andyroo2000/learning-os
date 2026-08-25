<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyMilestoneKey;
use App\Domain\Study\Models\StudyMilestone;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PresentStudyMilestonesAction
{
    /** @param list<StudyMilestoneKey> $keys */
    public function handle(int $userId, array $keys): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Study milestone user ID must be positive.');
        }

        if ($keys === []) {
            throw new InvalidArgumentException('At least one study milestone must be presented.');
        }

        DB::transaction(function () use ($userId, $keys): void {
            $user = User::query()->select('id')->whereKey($userId)->lockForUpdate()->first();
            if ($user === null) {
                throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
            }

            $presentedAt = now();

            StudyMilestone::query()
                ->where('user_id', $userId)
                ->whereNull('presented_at')
                ->whereIn('milestone_key', array_map(
                    static fn (StudyMilestoneKey $key): string => $key->value,
                    $keys,
                ))
                ->update(['presented_at' => $presentedAt, 'updated_at' => $presentedAt]);
        }, 3);
    }
}
