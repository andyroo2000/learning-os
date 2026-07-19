<?php

namespace App\Domain\Study\Models;

use Database\Factories\DailyAudioPracticeTrackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyAudioPracticeTrack extends Model
{
    /** @use HasFactory<DailyAudioPracticeTrackFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function newFactory(): DailyAudioPracticeTrackFactory
    {
        return DailyAudioPracticeTrackFactory::new();
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'script_units_json' => 'array',
            'timing_data' => 'array',
            'approx_duration_seconds' => 'integer',
            'generation_metadata_json' => 'array',
        ];
    }

    /** @return BelongsTo<DailyAudioPractice, $this> */
    public function practice(): BelongsTo
    {
        return $this->belongsTo(DailyAudioPractice::class, 'practice_id');
    }
}
