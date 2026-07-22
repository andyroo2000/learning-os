<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Data\GenerateAdminCourseDialogueData;

final class GenerateAdminCourseDialogueRequest extends ConvoLabAdminWriteRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'customPrompt' => ['nullable', 'string', 'max:100000'],
        ];
    }

    public function dialogueData(): GenerateAdminCourseDialogueData
    {
        return GenerateAdminCourseDialogueData::fromInput($this->safe()->only('customPrompt'));
    }
}
