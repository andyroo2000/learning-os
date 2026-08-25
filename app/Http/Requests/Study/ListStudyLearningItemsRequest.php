<?php

namespace App\Http\Requests\Study;

use App\Http\Requests\Api\CursorPaginatedRequest;

class ListStudyLearningItemsRequest extends CursorPaginatedRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $searchQuery = $this->input('q');

        if (is_string($searchQuery)) {
            $this->merge([
                'q' => trim($searchQuery),
            ]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'q' => ['sometimes', 'filled', 'string', 'max:255'],
        ];
    }

    public function searchQuery(): ?string
    {
        $validated = $this->validated();

        return array_key_exists('q', $validated) ? $validated['q'] : null;
    }

    protected function cursorParameters(): array
    {
        return ['created_at', 'id'];
    }
}
