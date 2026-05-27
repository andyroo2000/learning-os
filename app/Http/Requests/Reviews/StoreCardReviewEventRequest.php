<?php

namespace App\Http\Requests\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCardReviewEventRequest extends FormRequest
{
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
            'id' => ['nullable', 'ulid'],
            'card_id' => ['required', 'ulid', Rule::exists('cards', 'id')],
            'rating' => ['required', Rule::enum(CardReviewRating::class)],
            'reviewed_at' => ['required', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id' => $this->normalizeOptionalString($this->input('id')),
            'card_id' => is_string($this->input('card_id')) ? trim($this->input('card_id')) : $this->input('card_id'),
            'rating' => is_string($this->input('rating')) ? trim($this->input('rating')) : $this->input('rating'),
            'reviewed_at' => is_string($this->input('reviewed_at')) ? trim($this->input('reviewed_at')) : $this->input('reviewed_at'),
        ]);
    }

    private function normalizeOptionalString(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
