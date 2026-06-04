<?php

namespace App\Http\Requests\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateCardStudyStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Card $card */
        $card = $this->route('card');

        Gate::authorize('update', $card);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $studyStatus = $this->input('study_status');

        if (is_string($studyStatus)) {
            $this->merge([
                'study_status' => strtolower(trim($studyStatus)),
            ]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'study_status' => [
                'required',
                'string',
                Rule::in(CardStudyStatus::values()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'study_status.required' => 'study_status is required.',
        ];
    }
}
