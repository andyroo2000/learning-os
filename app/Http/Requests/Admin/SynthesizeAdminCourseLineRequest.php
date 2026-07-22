<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Data\SynthesizeAdminCourseLineData;

final class SynthesizeAdminCourseLineRequest extends ConvoLabAdminWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_string($this->input('voiceId'))) {
            $this->merge(['voiceId' => strtolower(trim($this->input('voiceId')))]);
        }
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'text' => ['required', 'string', 'max:'.SynthesizeAdminCourseLineData::MAX_TEXT_LENGTH],
            'voiceId' => ['required', 'string', 'regex:/^fishaudio:[a-f0-9]{32}$/'],
            'speed' => ['sometimes', 'numeric', 'min:0.5', 'max:2'],
            'unitIndex' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function synthesisData(): SynthesizeAdminCourseLineData
    {
        return SynthesizeAdminCourseLineData::fromInput(
            $this->safe()->only(['text', 'voiceId', 'speed', 'unitIndex']),
        );
    }
}
