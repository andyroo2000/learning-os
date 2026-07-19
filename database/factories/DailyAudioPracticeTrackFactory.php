<?php

namespace Database\Factories;

use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Models\DailyAudioPracticeTrack;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DailyAudioPracticeTrack> */
class DailyAudioPracticeTrackFactory extends Factory
{
    protected $model = DailyAudioPracticeTrack::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'practice_id' => DailyAudioPractice::factory(),
            'mode' => 'drill',
            'status' => 'ready',
            'title' => 'Drill',
            'sort_order' => 0,
            'script_units_json' => [],
            'audio_url' => '/audio/daily-practice.mp3',
            'timing_data' => [],
            'approx_duration_seconds' => 120,
            'generation_metadata_json' => null,
            'error_message' => null,
        ];
    }
}
