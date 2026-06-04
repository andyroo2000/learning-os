<?php

namespace App\Http\Requests\Flashcards;

use App\Http\Requests\Api\CursorPaginatedRequest;
use App\Http\Requests\Flashcards\Concerns\FiltersCardStudyStatus;

class ListDeckCardsRequest extends CursorPaginatedRequest
{
    use FiltersCardStudyStatus;

    protected function prepareForValidation(): void
    {
        $this->prepareStudyStatusForValidation();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'study_status' => $this->studyStatusRules(),
        ];
    }
}
