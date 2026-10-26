<?php

namespace App\Http\Requests\Study;

use Illuminate\Foundation\Http\FormRequest;

class ListStudyPilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'required', 'integer', 'min:0'],
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function cursor(): int
    {
        return (int) ($this->validated('cursor') ?? 0);
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 50);
    }
}
