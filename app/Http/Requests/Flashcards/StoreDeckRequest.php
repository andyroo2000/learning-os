<?php

namespace App\Http\Requests\Flashcards;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeckRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $courseId = $this->input('course_id');

        if (is_string($courseId)) {
            $this->merge([
                'course_id' => trim($courseId),
            ]);
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
