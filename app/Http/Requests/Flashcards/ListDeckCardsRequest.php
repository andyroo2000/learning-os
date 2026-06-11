<?php

namespace App\Http\Requests\Flashcards;

use App\Http\Requests\Api\CursorPaginatedRequest;
use App\Http\Requests\Flashcards\Concerns\FiltersCardStudyStatus;
use App\Http\Requests\Flashcards\Concerns\FiltersCardType;

class ListDeckCardsRequest extends CursorPaginatedRequest
{
    use FiltersCardStudyStatus;
    use FiltersCardType;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $searchQuery = $this->input('q');

        if (is_string($searchQuery)) {
            $this->merge([
                'q' => trim($searchQuery),
            ]);
        }

        $this->prepareStudyStatusForValidation();
        $this->prepareCardTypeForValidation();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'study_status' => $this->studyStatusRules(),
            'card_type' => $this->cardTypeRules(),
            'q' => ['sometimes', 'filled', 'string', 'max:255'],
        ];
    }

    public function searchQuery(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('q', $validated)) {
            return null;
        }

        return $validated['q'];
    }

    protected function cursorParameters(): array
    {
        return ['created_at', 'id'];
    }
}
