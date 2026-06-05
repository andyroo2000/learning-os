<?php

namespace App\Http\Requests\Concerns;

trait FiltersByDeckId
{
    use NormalizesUlidInput;

    protected function prepareDeckIdForValidation(): void
    {
        $input = [];

        $this->mergeNormalizedUlidInput($input, 'deck_id');

        if ($input !== []) {
            $this->merge($input);
        }
    }

    public function deckId(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('deck_id', $validated)) {
            return null;
        }

        return $validated['deck_id'];
    }
}
