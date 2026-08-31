<?php

namespace App\Domain\Achievements\Actions;

use App\Domain\Achievements\Models\AchievementAward;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReconcileAchievementAwardsAction
{
    public function __construct(
        private readonly GetAchievementCatalogAction $getCatalog,
        private readonly ResolveAchievementEarnedAtAction $resolveEarnedAt,
    ) {}

    /**
     * @param  array<string, int>  $metricValues
     * @return Collection<int, AchievementAward>
     */
    public function handle(
        int $userId,
        array $metricValues,
        ?array $thresholdReachedAt = null,
    ): Collection {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Achievement award user ID must be positive.');
        }

        foreach ($metricValues as $metricKey => $metricValue) {
            if ($metricKey === '' || $metricValue < 0) {
                throw new InvalidArgumentException('Achievement metric values must be non-negative.');
            }
        }

        $existingIds = AchievementAward::query()
            ->where('user_id', $userId)
            ->pluck('achievement_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        $missingAwards = [];

        foreach ($this->getCatalog->handle()['families'] as $family) {
            $metricKey = (string) $family['metricKey'];
            $metricValue = $metricValues[$metricKey] ?? 0;

            foreach ($family['tiers'] as $tier) {
                $threshold = (int) $tier['threshold'];
                $achievementId = $family['key'].'.'.$tier['key'];
                if ($metricValue < $threshold) {
                    break;
                }

                if (in_array($achievementId, $existingIds, true)) {
                    continue;
                }

                $persistedReachedAt = $thresholdReachedAt[$metricKey][(string) $threshold] ?? null;
                $earnedAt = is_string($persistedReachedAt)
                    ? CarbonImmutable::parse($persistedReachedAt)
                    : $this->resolveEarnedAt->handle($userId, $metricKey, $threshold);
                if ($earnedAt !== null) {
                    $missingAwards[] = [
                        'achievement_id' => $achievementId,
                        'earned_at' => $earnedAt,
                    ];
                }
            }
        }

        if ($missingAwards === [] && ! User::query()->whereKey($userId)->exists()) {
            throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
        }

        if ($missingAwards !== []) {
            DB::transaction(function () use ($userId, $missingAwards): void {
                $user = User::query()->select('id')->whereKey($userId)->lockForUpdate()->first();
                if ($user === null) {
                    throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
                }

                foreach ($missingAwards as $awardData) {
                    $exists = AchievementAward::query()
                        ->where('user_id', $userId)
                        ->where('achievement_id', $awardData['achievement_id'])
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    $award = new AchievementAward;
                    $award->user_id = $userId;
                    $award->achievement_id = $awardData['achievement_id'];
                    $award->earned_at = $awardData['earned_at'];
                    $award->save();
                }
            }, 3);
        }

        return AchievementAward::query()
            ->where('user_id', $userId)
            ->orderByDesc('earned_at')
            ->orderByDesc('id')
            ->get();
    }
}
