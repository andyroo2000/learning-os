<?php

namespace App\Domain\Study\Models;

use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Enums\StudyActivityOrigin;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyActivitySession extends Model
{
    use HasUlids;

    protected $guarded = ['id', 'user_id', 'source_key'];

    protected function casts(): array
    {
        return [
            'category' => StudyActivityCategory::class,
            'activity' => StudyActivityKind::class,
            'source' => StudyActivitySource::class,
            'origin' => StudyActivityOrigin::class,
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
            'audio_playback_ms' => 'integer',
            'cards_created' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
