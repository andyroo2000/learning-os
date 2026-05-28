<?php

namespace App\Http\Requests\Flashcards;

use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is validated on deck_id so clients get a field-specific 422.
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'id' => ['nullable', 'ulid'],
            'deck_id' => [
                'required',
                'ulid',
                Rule::exists(Deck::class, 'id')->where('user_id', $userId),
            ],
            'front_text' => ['required', 'string'],
            'back_text' => ['required', 'string'],
        ];
    }
}
