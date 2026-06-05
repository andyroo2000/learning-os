<?php

namespace App\Http\Requests\Study;

use App\Http\Requests\Concerns\NormalizesUlidInput;
use Illuminate\Foundation\Http\FormRequest;

class StoreStudyReviewUndoRequest extends FormRequest
{
    use NormalizesUlidInput;

    protected function prepareForValidation(): void
    {
        $normalized = [];

        $this->mergeNormalizedUlidInput($normalized, 'reviewLogId');

        $timeZone = $this->input('timeZone');

        // Leave non-string values untouched so validation reports type errors instead of coercing them.
        if (is_string($timeZone)) {
            $normalized['timeZone'] = trim($timeZone);
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reviewLogId' => ['required', 'ulid'],
            'timeZone' => ['sometimes', 'nullable', 'string', 'timezone'],
            // currentOverview is accepted for ConvoLab request compatibility; controllers recompute overview.
            'currentOverview' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function reviewLogId(): string
    {
        return (string) $this->validated()['reviewLogId'];
    }

    public function timeZone(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('timeZone', $validated)) {
            return null;
        }

        return $validated['timeZone'] === null ? null : (string) $validated['timeZone'];
    }
}
