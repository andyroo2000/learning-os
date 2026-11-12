<?php

namespace Database\Factories;

use App\Domain\Journal\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JournalEntry> */
class JournalEntryFactory extends Factory
{
    protected $model = JournalEntry::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'entry_date' => '2026-11-12',
            'title' => null,
            'body' => '',
        ];
    }
}
