<?php

namespace App\Http\Requests\Flashcards;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeckRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id' => $this->normalizeOptionalString($this->input('id')),
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'description' => $this->normalizeOptionalString($this->input('description')),
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
