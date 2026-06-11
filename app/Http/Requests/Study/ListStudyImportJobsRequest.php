<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Http\Requests\Api\CursorPaginatedRequest;
use Illuminate\Validation\Rule;

class ListStudyImportJobsRequest extends CursorPaginatedRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $status = $this->input('status');

        if (is_string($status)) {
            $this->merge([
                'status' => strtolower(trim($status)),
            ]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'status' => ['sometimes', 'filled', Rule::enum(StudyImportStatus::class)],
        ];
    }

    public function status(): ?StudyImportStatus
    {
        $validated = $this->validated();

        if (! array_key_exists('status', $validated)) {
            return null;
        }

        return StudyImportStatus::from($validated['status']);
    }

    protected function cursorParameters(): array
    {
        return ['updated_at', 'id'];
    }
}
