<?php

namespace App\Domain\Study\Models;

use App\Domain\Study\Enums\StudyMilestoneKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyMilestone extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'milestone_key' => StudyMilestoneKey::class,
            'earned_at' => 'immutable_datetime',
            'presented_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
