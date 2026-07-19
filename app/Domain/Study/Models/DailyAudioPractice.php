<?php

namespace App\Domain\Study\Models;

use App\Models\User;
use Database\Factories\DailyAudioPracticeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyAudioPractice extends Model
{
    /** @use HasFactory<DailyAudioPracticeFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function newFactory(): DailyAudioPracticeFactory
    {
        return DailyAudioPracticeFactory::new();
    }

    protected function casts(): array
    {
        return [
            'practice_date' => 'date:Y-m-d',
            'target_duration_minutes' => 'integer',
            'source_card_ids_json' => 'array',
            'selection_summary_json' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<DailyAudioPracticeTrack, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(DailyAudioPracticeTrack::class, 'practice_id');
    }
}
