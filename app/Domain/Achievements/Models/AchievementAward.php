<?php

namespace App\Domain\Achievements\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AchievementAward extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'earned_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
