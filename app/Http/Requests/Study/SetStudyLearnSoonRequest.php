<?php

namespace App\Http\Requests\Study;

use Illuminate\Foundation\Http\FormRequest;

class SetStudyLearnSoonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cardIds' => ['required', 'array', 'min:1', 'max:100'],
            'cardIds.*' => ['required', 'string', 'ulid'],
            'learnSoon' => ['required', 'boolean'],
        ];
    }
}
