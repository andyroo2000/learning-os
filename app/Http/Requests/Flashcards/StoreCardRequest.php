<?php

namespace App\Http\Requests\Flashcards;

use App\Domain\Flashcards\Enums\CardType;
use App\Http\Requests\Flashcards\Concerns\ValidatesCardVariantMetadata;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCardRequest extends FormRequest
{
    use ValidatesCardVariantMetadata;

    // Keep aligned with the required string fields in rules(); arrays must still reach validation unchanged.
    private const TRIMMED_INPUT_KEYS = ['front_text', 'back_text'];

    protected function prepareForValidation(): void
    {
        // This intentionally mirrors CreateCardData normalization before validation,
        // while the DTO protects programmatic callers that bypass HTTP.
        $normalized = [];

        foreach (['id', 'deck_id'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = CanonicalUlid::normalize($value);
            }
        }

        foreach (self::TRIMMED_INPUT_KEYS as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = trim($value);
            }
        }

        if (array_key_exists('card_type', $this->all())) {
            $value = $this->input('card_type');

            if (is_string($value)) {
                $normalized['card_type'] = strtolower(trim($value));
            }
        }

        $this->mergeNormalizedCardVariantMetadataForValidation($normalized);

        if ($normalized !== []) {
            $this->merge($normalized);
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
            // Ownership/existence stays in CreateCardAction so idempotent replays can
            // resolve existing card IDs even after a deck has been soft-deleted.
            'deck_id' => ['required', 'ulid'],
            'front_text' => ['required', 'string'],
            'back_text' => ['required', 'string'],
            'card_type' => ['sometimes', 'required', 'string', Rule::in(CardType::values())],
            'prompt_json' => ['sometimes', 'nullable', 'array'],
            'answer_json' => ['sometimes', 'nullable', 'array'],
            ...$this->cardVariantMetadataRules(),
        ];
    }
}
