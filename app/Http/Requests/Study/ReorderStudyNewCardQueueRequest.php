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
            // `present` lets compatibility clients distinguish missing cardIds from an explicit null/list shape.
            'cardIds' => ['present', 'array', 'min:1', 'max:'.NewCardQueueLimits::PAGE_SIZE_MAX],
            'cardIds.*' => ['required', 'string', 'distinct', 'ulid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cardIds.present' => 'cardIds is required.',
            'cardIds.array' => 'cardIds must be an array.',
            'cardIds.min' => 'cardIds must include between 1 and '.NewCardQueueLimits::PAGE_SIZE_MAX.' cards.',
            'cardIds.max' => 'cardIds must include between 1 and '.NewCardQueueLimits::PAGE_SIZE_MAX.' cards.',
            'cardIds.*.required' => 'Each cardId must be a valid ULID.',
            'cardIds.*.string' => 'Each cardId must be a valid ULID.',
            'cardIds.*.distinct' => 'cardIds must not contain duplicates.',
            'cardIds.*.ulid' => 'Each cardId must be a valid ULID.',
        ];
    }
}
