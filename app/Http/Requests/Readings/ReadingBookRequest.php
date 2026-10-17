<?php

namespace App\Http\Requests\Readings;

use Illuminate\Foundation\Http\FormRequest;

final class ReadingBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['id', 'title'] as $key) {
            if (is_string($this->input($key))) {
                $value = trim($this->input($key));
                $this->merge([$key => $key === 'id' ? strtolower($value) : $value]);
            }
        }
        if (is_array($this->input('readingIds'))) {
            $this->merge(['readingIds' => array_map(
                fn ($id) => is_string($id) ? strtolower(trim($id)) : $id,
                $this->input('readingIds')
            )]);
        }
    }

    public function rules(): array
    {
        return match ($this->method()) {
            'POST' => ['viewAs' => ['prohibited'], 'id' => ['required', 'ulid'], 'title' => ['required', 'string', 'max:200']],
            'PUT' => ['viewAs' => ['prohibited'], 'expectedRevision' => ['required', 'integer', 'min:1'],
                'readingIds' => ['present', 'array', 'list', 'max:500'], 'readingIds.*' => ['required', 'ulid', 'distinct']],
            'PATCH' => ['viewAs' => ['prohibited'], 'expectedRevision' => ['required', 'integer', 'min:1'],
                'title' => ['required', 'string', 'max:200']],
            default => ['viewAs' => ['prohibited'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']],
        };
    }
}
