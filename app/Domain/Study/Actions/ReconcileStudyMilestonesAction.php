<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyMilestoneKey;
use App\Domain\Study\Models\StudyMilestone;
use App\Domain\Study\Models\StudyMilestoneProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReconcileStudyMilestonesAction
{
    /** @return Collection<int, StudyMilestone> */
    public function handle(int $userId, int $burnedCount, ?Carbon $now = null): Collection
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Study milestone user ID must be positive.');
        }

        if ($burnedCount < 0) {
            throw new InvalidArgumentException('Study milestone burned count cannot be negative.');
        }

        $now ??= now();

        return DB::transaction(function () use ($userId, $burnedCount, $now): Collection {
            $user = User::query()->select('id')->whereKey($userId)->lockForUpdate()->first();
            if ($user === null) {
                throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
            }

            $profile = StudyMilestoneProfile::query()
                ->whereKey($userId)
                ->lockForUpdate()
                ->first();

            if ($profile === null) {
                $profile = new StudyMilestoneProfile;
                $profile->user_id = $userId;
                $profile->initialized_at = $now;
                $profile->save();

                // Existing achievements are history, not surprise celebrations. The
                // first server-aware client establishes this baseline for the account.
                foreach ($this->qualifiedKeys($burnedCount) as $key) {
                    $this->createMilestone($userId, $key, $now, $now);
                }
            } else {
                $qualifiedKeys = $this->qualifiedKeys($burnedCount);
                $qualifiedValues = array_map(
                    static fn (StudyMilestoneKey $key): string => $key->value,
                    $qualifiedKeys,
                );

                $unqualifiedValues = array_values(array_diff(
                    array_map(
                        static fn (StudyMilestoneKey $key): string => $key->value,
                        StudyMilestoneKey::cases(),
                    ),
                    $qualifiedValues,
                ));

                if ($unqualifiedValues !== []) {
                    StudyMilestone::query()
                        ->where('user_id', $userId)
                        ->whereNull('presented_at')
                        ->whereIn('milestone_key', $unqualifiedValues)
                        ->delete();
                }

                $earnedValues = StudyMilestone::query()
                    ->where('user_id', $userId)
                    ->pluck('milestone_key')
                    ->map(static fn (mixed $key): string => $key instanceof StudyMilestoneKey ? $key->value : (string) $key)
                    ->all();

                foreach ($qualifiedKeys as $key) {
                    if (! in_array($key->value, $earnedValues, true)) {
                        $this->createMilestone($userId, $key, $now, null);
                    }
                }
            }

            return StudyMilestone::query()
                ->where('user_id', $userId)
                ->orderByDesc('earned_at')
                ->orderByDesc('id')
                ->get();
        }, 3);
    }

    /** @return list<StudyMilestoneKey> */
    private function qualifiedKeys(int $burnedCount): array
    {
        return array_values(array_filter(
            StudyMilestoneKey::cases(),
            static fn (StudyMilestoneKey $key): bool => $burnedCount >= $key->threshold(),
        ));
    }

    private function createMilestone(
        int $userId,
        StudyMilestoneKey $key,
        Carbon $earnedAt,
        ?Carbon $presentedAt,
    ): void {
        $milestone = new StudyMilestone;
        $milestone->user_id = $userId;
        $milestone->milestone_key = $key;
        $milestone->earned_at = $earnedAt;
        $milestone->presented_at = $presentedAt;
        $milestone->save();
    }
}
