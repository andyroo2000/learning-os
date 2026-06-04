<?php

namespace App\Http\Requests\Courses;

use Illuminate\Foundation\Http\FormRequest;

class StoreCourseRequest extends FormRequest
{
    public const DESCRIPTION_MAX_LENGTH = 2000;

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['id', 'title', 'description', 'native_language', 'target_language'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $trimmed[$key] = trim($value);
            }
        }

        $this->merge($trimmed);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'id' => ['nullable', 'ulid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH],
            // Keep these free-form for now so products can choose BCP 47 tags or product-local language codes.
            'native_language' => ['required', 'string', 'max:16'],
            'target_language' => ['required', 'string', 'max:16'],
        ];
    }
}
