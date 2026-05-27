<?php

namespace App\Http\Requests\Flashcards;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCardRequest extends FormRequest
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
            'deck_id' => ['required', 'ulid', Rule::exists('decks', 'id')],
            'front_text' => ['required', 'string'],
            'back_text' => ['required', 'string'],
        ];
    }
}
