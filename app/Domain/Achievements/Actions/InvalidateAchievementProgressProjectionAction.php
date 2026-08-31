<?php

namespace App\Domain\Achievements\Actions;

use App\Domain\Achievements\Models\AchievementProgressProjection;
use InvalidArgumentException;

final class InvalidateAchievementProgressProjectionAction
{
    public function handle(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Achievement progress user ID must be positive.');
        }

        AchievementProgressProjection::query()
            ->whereKey($userId)
            ->update(['needs_rebuild' => true]);
    }
}
