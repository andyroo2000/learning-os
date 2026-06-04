<?php

namespace App\Http\Requests\Media;

use App\Http\Requests\Api\CursorPaginatedRequest;

class ListMediaAssetsRequest extends CursorPaginatedRequest
{
    protected function prepareForValidation(): void
    {
        $courseId = $this->input('course_id');

        if (is_string($courseId)) {
            $this->merge([
                'course_id' => trim($courseId),
            ]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'course_id' => ['sometimes', 'filled', 'ulid'],
        ];
    }

    public function courseId(): ?string
    {
        if (! $this->has('course_id')) {
            return null;
        }

        return (string) $this->input('course_id');
    }
}
