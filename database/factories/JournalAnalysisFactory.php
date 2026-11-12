<?php

namespace Database\Factories;

use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JournalAnalysis> */
class JournalAnalysisFactory extends Factory
{
    protected $model = JournalAnalysis::class;

    public function definition(): array
    {
        return [
            'entry_id' => JournalEntry::factory(),
            'source_version' => 1,
            'source_body' => '昨日は映画を見た。',
        ];
    }
}
