<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Support\NewCardQueueLimits;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Foundation\Http\FormRequest;

class ReorderStudyNewCardQueueRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $cardIds = $this->input('cardIds');

        if (! is_array($cardIds)) {
            return;
        }

        $this->merge([
            'cardIds' => array_map(
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
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'cardIds' => ['required', 'array', 'min:1', 'max:'.NewCardQueueLimits::PAGE_SIZE_MAX],
            'cardIds.*' => ['required', 'string', 'distinct', 'ulid'],
        ];
    }
}
