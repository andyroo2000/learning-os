<?php

namespace App\Http\Requests\Media;

use App\Http\Requests\Api\CursorPaginatedRequest;
use App\Http\Requests\Concerns\FiltersByDeckId;

class ListMediaAssetsRequest extends CursorPaginatedRequest
{
    use FiltersByDeckId;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $normalized = [];

        $this->mergeNormalizedUlidInput($normalized, 'course_id');

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

    protected function cursorParameters(): array
    {
        return ['created_at', 'id'];
    }
}
