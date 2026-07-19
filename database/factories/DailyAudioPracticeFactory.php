<?php

namespace Database\Factories;

use App\Domain\Study\Models\DailyAudioPractice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DailyAudioPractice> */
class DailyAudioPracticeFactory extends Factory
{
    protected $model = DailyAudioPractice::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'convolab_user_id' => null,
            'practice_date' => today(),
            'status' => 'ready',
            'target_duration_minutes' => 30,
            'target_language' => 'ja',
            'native_language' => 'en',
            'source_card_ids_json' => [],
            'selection_summary_json' => null,
            'error_message' => null,
        ];
    }
}
