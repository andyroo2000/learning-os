<?php

namespace Database\Factories;

use App\Domain\Journal\Models\JournalClarification;
use App\Domain\Journal\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JournalClarification> */
class JournalClarificationFactory extends Factory
{
    protected $model = JournalClarification::class;

    public function definition(): array
    {
        return [
            'entry_id' => JournalEntry::factory(),
            'source_version' => 1,
            'source_body' => '車で運転するながら、モーリさんは映画を調べた。',
        ];
    }
}
