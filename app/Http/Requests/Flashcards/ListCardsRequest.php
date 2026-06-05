<?php

namespace App\Http\Requests\Flashcards;

use App\Http\Requests\Api\CursorPaginatedRequest;
use App\Http\Requests\Flashcards\Concerns\FiltersCardStudyStatus;
use App\Support\Identifiers\CanonicalUlid;

class ListCardsRequest extends CursorPaginatedRequest
{
    use FiltersCardStudyStatus;

    protected function prepareForValidation(): void
    {
        $courseId = $this->input('course_id');
        $searchQuery = $this->input('q');

        if (is_string($courseId)) {
            $this->merge([
                'course_id' => CanonicalUlid::normalize($courseId),
            ]);
        }

        if (is_string($searchQuery)) {
            $this->merge([
                'q' => trim($searchQuery),
            ]);
        }

        $this->prepareStudyStatusForValidation();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'course_id' => ['sometimes', 'filled', 'ulid'],
            'study_status' => $this->studyStatusRules(),
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

    public function searchQuery(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('q', $validated)) {
            return null;
        }

        return $validated['q'];
    }
}
