<?php

namespace App\Http\Requests\Flashcards\Concerns;

use App\Domain\Flashcards\Enums\CardType;
use Illuminate\Validation\Rule;

trait FiltersCardType
{
    protected function prepareCardTypeForValidation(): void
    {
        $cardType = $this->input('card_type');

        if (is_string($cardType)) {
            $this->merge([
                'card_type' => strtolower(trim($cardType)),
            ]);
        }
    }

    /**
     * @return list<mixed>
     */
    protected function cardTypeRules(): array
    {
        return ['sometimes', 'filled', Rule::enum(CardType::class)];
    }

    public function cardType(): ?CardType
    {
        $validated = $this->validated();

        if (! array_key_exists('card_type', $validated)) {
            return null;
        }

        return CardType::from($validated['card_type']);
    }
}
