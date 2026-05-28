<?php

namespace Database\Factories;

use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deck>
 */
class DeckFactory extends Factory
{
    protected $model = Deck::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
