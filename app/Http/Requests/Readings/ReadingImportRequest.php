<?php

namespace App\Http\Requests\Readings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

final class ReadingImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('document'))) {
            $this->merge(['document' => json_decode($this->input('document'), true)]);
        }
        if (is_string($this->input('id'))) {
            $this->merge(['id' => strtolower(trim($this->input('id')))]);
        }
    }

    public function after(): array
    {
        return [function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $bytes = strlen(json_encode($this->input('document')) ?: '');
            foreach (Arr::flatten($this->allFiles()) as $file) {
                $bytes += $file->getSize();
            }
            if ($bytes > 24_000_000) {
                $validator->errors()->add('document', 'Import bundle exceeds 24 MB.');
            }
        }];
    }

    public function rules(): array
    {
        return ['viewAs' => ['prohibited'], 'id' => ['required', 'ulid'],
            'expectedRevision' => ['sometimes', 'integer', 'min:1'],
            'document' => ['required', 'array'],
            'images' => ['sometimes', 'array', 'max:100'],
            'images.*' => ['file', 'image', 'mimes:jpeg,png', 'max:9765'],
            'cover' => ['sometimes', 'file', 'image', 'mimes:jpeg,png', 'max:9765']];
    }
}
