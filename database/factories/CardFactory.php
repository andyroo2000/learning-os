<?php

namespace Database\Factories;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    protected $model = Card::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deck_id' => Deck::factory(),
            'front_text' => fake()->sentence(),
            'back_text' => fake()->sentence(),
        ];
    }
}
