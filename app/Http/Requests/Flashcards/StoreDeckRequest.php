<?php

namespace App\Http\Requests\Flashcards;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeckRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Trim and lowercase before validation so padded or uppercase client ULIDs pass Laravel's ulid rule.
        $normalized = [];

        foreach (['id', 'course_id'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = CanonicalUlid::normalize($value);
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
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
            'id' => ['nullable', 'ulid'],
            'course_id' => ['nullable', 'ulid'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
