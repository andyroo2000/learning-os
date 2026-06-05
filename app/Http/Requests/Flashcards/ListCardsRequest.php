<?php

namespace App\Http\Requests\Flashcards;

use App\Http\Requests\Api\CursorPaginatedRequest;
use App\Http\Requests\Flashcards\Concerns\FiltersCardStudyStatus;
use App\Http\Requests\Flashcards\Concerns\FiltersCardType;
use App\Support\Identifiers\CanonicalUlid;

class ListCardsRequest extends CursorPaginatedRequest
{
    use FiltersCardStudyStatus;
    use FiltersCardType;

    protected function prepareForValidation(): void
    {
        $normalized = [];
        $searchQuery = $this->input('q');

        foreach (['course_id', 'deck_id'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = CanonicalUlid::normalize($value);
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }

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
            'course_id' => ['sometimes', 'filled', 'ulid'],
            'deck_id' => ['sometimes', 'filled', 'ulid'],
            'study_status' => $this->studyStatusRules(),
            'card_type' => $this->cardTypeRules(),
            'q' => ['sometimes', 'filled', 'string', 'max:255'],
        ];
    }

    public function courseId(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('course_id', $validated)) {
            return null;
        }

        return $validated['course_id'];
    }

    public function deckId(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('deck_id', $validated)) {
            return null;
        }

        return $validated['deck_id'];
    }

    public function searchQuery(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('q', $validated)) {
            return null;
        }

        return $validated['q'];
    }
}
