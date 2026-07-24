<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Models\StudySettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateStudySettingsRequest extends FormRequest
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
        $rules = [
            'integer',
            'min:0',
            'max:'.StudySettings::MAX_NEW_CARDS_PER_DAY,
        ];

        return [
            'newCardsPerDay' => ['required_without:new_cards_per_day', ...$rules],
            'new_cards_per_day' => ['required_without:newCardsPerDay', ...$rules],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->any()) {
                    return;
                }

                $validated = $validator->safe()->all();

                if (
                    array_key_exists('newCardsPerDay', $validated)
                    && array_key_exists('new_cards_per_day', $validated)
                    && (int) $validated['newCardsPerDay'] !== (int) $validated['new_cards_per_day']
                ) {
                    $validator->errors()->add(
                        'newCardsPerDay',
                        'The newCardsPerDay and new_cards_per_day values must match when both are provided.',
                    );
                }
            },
        ];
    }

    public function newCardsPerDay(): int
    {
        $validated = $this->validated();

        return (int) ($validated['newCardsPerDay'] ?? $validated['new_cards_per_day']);
    }
}
