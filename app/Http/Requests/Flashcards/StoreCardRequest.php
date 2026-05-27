<?php

namespace App\Http\Requests\Flashcards;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCardRequest extends FormRequest
{
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
            'id' => ['nullable', 'ulid'],
            'deck_id' => ['required', 'ulid', Rule::exists('decks', 'id')],
            'front_text' => ['required', 'string'],
            'back_text' => ['required', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id' => $this->normalizeOptionalString($this->input('id')),
            'deck_id' => is_string($this->input('deck_id')) ? trim($this->input('deck_id')) : $this->input('deck_id'),
            'front_text' => is_string($this->input('front_text')) ? trim($this->input('front_text')) : $this->input('front_text'),
            'back_text' => is_string($this->input('back_text')) ? trim($this->input('back_text')) : $this->input('back_text'),
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
