<?php

namespace App\Http\Requests\Flashcards;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Card $card */
        $card = $this->route('card');

        // Throw via Gate so CardPolicy's 404 denial is preserved; returning false here would become a 403.
        Gate::authorize('update', $card);

        return true;
    }

    protected function prepareForValidation(): void
    {
        // Trim before validation so whitespace-only input does not depend on global middleware.
        $normalized = [
            'front_text' => $this->trimStringInput('front_text'),
            'back_text' => $this->trimStringInput('back_text'),
        ];

        if (array_key_exists('card_type', $this->all())) {
            $value = $this->input('card_type');
            $normalized['card_type'] = is_string($value) ? strtolower(trim($value)) : $value;
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'front_text' => ['required', 'string'],
            'back_text' => ['required', 'string'],
            'card_type' => ['sometimes', 'required', 'string', Rule::in(CardType::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'front_text.required' => 'front_text is required.',
            'back_text.required' => 'back_text is required.',
        ];
    }

    private function trimStringInput(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : $value;
    }
}
