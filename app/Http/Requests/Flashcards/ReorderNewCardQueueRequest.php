<?php

namespace App\Http\Requests\Flashcards;

use App\Domain\Flashcards\Support\NewCardQueueLimits;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Foundation\Http\FormRequest;

class ReorderNewCardQueueRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $cardIds = $this->input('card_ids');

        if (! is_array($cardIds)) {
            return;
        }

        $this->merge([
            'card_ids' => array_map(
                fn (mixed $cardId): mixed => is_string($cardId) ? CanonicalUlid::normalize($cardId) : $cardId,
                $cardIds,
            ),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'card_ids' => ['required', 'array', 'min:1', 'max:'.NewCardQueueLimits::PAGE_SIZE_MAX],
            'card_ids.*' => ['required', 'string', 'distinct', 'ulid'],
        ];
    }
}
