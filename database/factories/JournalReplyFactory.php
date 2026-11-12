<?php

namespace Database\Factories;

use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalReply;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JournalReply> */
class JournalReplyFactory extends Factory
{
    protected $model = JournalReply::class;

    public function definition(): array
    {
        return ['analysis_id' => JournalAnalysis::factory(), 'base_revision' => 0,
            'answer' => '友達と見ました。'];
    }
}
