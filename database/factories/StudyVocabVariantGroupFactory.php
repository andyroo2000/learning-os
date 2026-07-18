<?php

namespace Database\Factories;

use App\Domain\Study\Models\StudyVocabVariantGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudyVocabVariantGroup>
 */
class StudyVocabVariantGroupFactory extends Factory
{
    protected $model = StudyVocabVariantGroup::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'target_word' => '勉強',
            'target_reading' => null,
            'target_meaning' => null,
            'source_sentence' => null,
            'source_context' => null,
            'include_learner_context' => true,
        ];
    }
}
