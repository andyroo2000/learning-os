<?php

namespace Database\Factories;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardReviewEvent>
 */
class CardReviewEventFactory extends Factory
{
    protected $model = CardReviewEvent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_id' => Card::factory(),
            'rating' => fake()->randomElement(['again', 'hard', 'good', 'easy']),
            'reviewed_at' => now(),
        ];
    }
}
