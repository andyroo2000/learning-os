<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Enums\StudyMilestoneKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PresentStudyMilestonesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'milestoneIds' => ['required', 'array', 'min:1', 'max:16'],
            'milestoneIds.*' => ['required', 'string', 'distinct', Rule::enum(StudyMilestoneKey::class)],
        ];
    }

    /** @return list<StudyMilestoneKey> */
    public function milestoneKeys(): array
    {
        /** @var list<string> $values */
        $values = $this->validated('milestoneIds');

        return array_map(StudyMilestoneKey::from(...), $values);
    }
}
