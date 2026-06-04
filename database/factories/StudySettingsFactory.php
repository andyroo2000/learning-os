<?php

namespace Database\Factories;

use App\Domain\Study\Models\StudySettings;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudySettings>
 */
class StudySettingsFactory extends Factory
{
    protected $model = StudySettings::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'new_cards_per_day' => StudySettings::DEFAULT_NEW_CARDS_PER_DAY,
        ];
    }
}
