<?php

namespace App\Http\Requests\Content;

use Illuminate\Foundation\Http\FormRequest;

class ListContentEpisodesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [
            'limit' => $this->input('limit', 50),
            'offset' => $this->input('offset', 0),
        ];

        if ($this->has('library')) {
            $library = $this->input('library');
            $normalized['library'] = match ($library) {
                'true' => true,
                'false' => false,
                default => $library,
            };
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'library' => ['sometimes', 'boolean'],
            'limit' => ['required', 'integer', 'min:1', 'max:100'],
            'offset' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function library(): bool
    {
        return (bool) $this->validated('library', false);
    }

    public function limit(): int
    {
        return (int) $this->validated('limit');
    }

    public function offset(): int
    {
        return (int) $this->validated('offset');
    }
}
