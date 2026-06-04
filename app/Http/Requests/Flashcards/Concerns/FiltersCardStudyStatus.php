<?php

namespace App\Http\Requests\Flashcards\Concerns;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use Illuminate\Validation\Rule;

trait FiltersCardStudyStatus
{
    protected function prepareStudyStatusForValidation(): void
    {
        $studyStatus = $this->input('study_status');

        if (is_string($studyStatus)) {
            $this->merge([
                'study_status' => strtolower(trim($studyStatus)),
            ]);
        }
    }

    /**
     * @return list<mixed>
     */
    protected function studyStatusRules(): array
    {
        return ['sometimes', 'filled', Rule::enum(CardStudyStatus::class)];
    }

    public function studyStatus(): ?CardStudyStatus
    {
        $validated = $this->validated();

        if (! array_key_exists('study_status', $validated)) {
            return null;
        }

        return CardStudyStatus::from($validated['study_status']);
    }
}
