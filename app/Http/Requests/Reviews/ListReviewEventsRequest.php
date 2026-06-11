<?php

namespace App\Http\Requests\Reviews;

use App\Http\Requests\Api\CursorPaginatedRequest;
use App\Http\Requests\Concerns\FiltersByDeckId;

class ListReviewEventsRequest extends CursorPaginatedRequest
{
    use FiltersByDeckId;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $normalized = [];

        foreach (['course_id', 'card_id'] as $key) {
            $this->mergeNormalizedUlidInput($normalized, $key);
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }

        $this->prepareDeckIdForValidation();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'course_id' => ['sometimes', 'filled', 'ulid'],
            'deck_id' => ['sometimes', 'filled', 'ulid'],
            'card_id' => ['sometimes', 'filled', 'ulid'],
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

    public function cardId(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('card_id', $validated)) {
            return null;
        }

        return $validated['card_id'];
    }

    protected function cursorParameters(): array
    {
        return ['card_review_events.reviewed_at', 'card_review_events.id'];
    }
}
