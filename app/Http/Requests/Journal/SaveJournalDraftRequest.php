<?php

namespace App\Http\Requests\Journal;

use App\Domain\Journal\Support\JournalDraftInput;
use Illuminate\Foundation\Http\FormRequest;

class SaveJournalDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(JournalDraftInput::normalize($this->all()));
    }

    public function rules(): array
    {
        return JournalDraftInput::rules();
    }
}
