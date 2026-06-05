<?php

namespace App\Http\Requests\Concerns;

use App\Support\Identifiers\CanonicalUlid;

trait FiltersByDeckId
{
    protected function prepareDeckIdForValidation(): void
    {
        $deckId = $this->input('deck_id');

        if (is_string($deckId)) {
            $this->merge([
                'deck_id' => CanonicalUlid::normalize($deckId),
            ]);
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
