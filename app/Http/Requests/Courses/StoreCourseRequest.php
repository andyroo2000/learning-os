<?php

namespace App\Http\Requests\Courses;

use Illuminate\Foundation\Http\FormRequest;

class StoreCourseRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'native_language' => ['required', 'string', 'max:16'],
            'target_language' => ['required', 'string', 'max:16'],
        ];
    }
}
