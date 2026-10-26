<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Support\StudyPileInput;
use Illuminate\Foundation\Http\FormRequest;

class SaveStudyPileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(StudyPileInput::normalize($this->all()));
    }

    public function rules(): array
    {
        return StudyPileInput::rules();
    }
}
