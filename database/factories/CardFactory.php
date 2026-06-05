<?php

namespace Database\Factories;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Support\CardSearchText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    protected $model = Card::class;

    public function configure(): static
    {
        return $this->afterMaking(function (Card $card): void {
            if (($card->search_text ?? '') !== '') {
                return;
            }

            $card->search_text = CardSearchText::fromContent(
                frontText: $card->front_text,
                backText: $card->back_text,
                promptJson: $card->prompt_json,
                answerJson: $card->answer_json,
            );
        });
    }

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
            'card_type' => CardType::Recognition,
        ];
    }
}
