<?php

namespace App\Http\Requests\Flashcards;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeckRequest extends FormRequest
{
    // Keep aligned with the string fields in rules(); omitted optional fields must stay omitted.
    private const TRIMMED_INPUT_KEYS = ['name', 'description'];

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

        foreach (self::TRIMMED_INPUT_KEYS as $key) {
            if ($this->exists($key)) {
                $value = $this->input($key);
                $normalized[$key] = is_string($value) ? trim($value) : $value;
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
