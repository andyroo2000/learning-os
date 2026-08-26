<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Models\CardIntroductionCohort;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Foundation\Http\FormRequest;

class StoreLessonFollowupCohortRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $cohortId = $this->input('cohortId');
        $cardIds = $this->input('cardIds');
        $label = $this->input('label');

        $this->merge([
            'cohortId' => is_string($cohortId) ? CanonicalUlid::normalize($cohortId) : $cohortId,
            'cardIds' => is_array($cardIds)
                ? array_map(
                    fn ($cardId) => is_string($cardId) ? CanonicalUlid::normalize($cardId) : $cardId,
                    $cardIds,
                )
                : $cardIds,
            'label' => is_string($label) ? trim($label) : $label,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'cohortId' => ['required', 'ulid'],
            'cardIds' => ['required', 'array', 'min:1', 'max:100'],
            'cardIds.*' => ['required', 'ulid', 'distinct:strict'],
            'label' => ['sometimes', 'nullable', 'string', 'max:'.CardIntroductionCohort::MAX_LABEL_LENGTH],
        ];
    }

    public function cohortId(): string
    {
        return (string) $this->validated('cohortId');
    }

    /** @return list<string> */
    public function cardIds(): array
    {
        /** @var list<string> $cardIds */
        $cardIds = $this->validated('cardIds');

        return $cardIds;
    }

    public function label(): ?string
    {
        $label = $this->validated('label');

        return is_string($label) && $label !== '' ? $label : null;
    }
}
