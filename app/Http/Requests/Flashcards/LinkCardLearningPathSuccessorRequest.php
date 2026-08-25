<?php

namespace App\Http\Requests\Flashcards;

use App\Domain\Flashcards\Enums\CardProgressionUnlockRequirement;
use App\Domain\Flashcards\Models\Card;
use App\Http\Requests\Concerns\NormalizesUlidInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LinkCardLearningPathSuccessorRequest extends FormRequest
{
    use NormalizesUlidInput;

    public function authorize(): bool
    {
        /** @var Card $card */
        $card = $this->route('card');

        Gate::authorize('update', $card);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        $this->mergeNormalizedUlidInput($normalized, 'successor_card_id');
        $this->merge($normalized);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'successor_card_id' => ['required', 'string', 'ulid'],
            'unlock_requirement' => [
                'sometimes',
                'string',
                Rule::in(CardProgressionUnlockRequirement::values()),
            ],
        ];
    }

    public function unlockRequirement(): CardProgressionUnlockRequirement
    {
        $value = $this->validated('unlock_requirement');

        return is_string($value)
            ? CardProgressionUnlockRequirement::from($value)
            : CardProgressionUnlockRequirement::SuccessfulRetrieval;
    }
}
