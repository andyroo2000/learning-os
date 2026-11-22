<?php

namespace App\Http\Requests\Journal;

use App\Domain\Journal\Support\JournalDraftInput;
use Illuminate\Foundation\Http\FormRequest;

class SaveJournalDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A browser may retain an offline draft after another tab changes the session.
        return ! $this->headers->has('X-Journal-Owner')
            || $this->header('X-Journal-Owner') === (string) $this->user()?->getAuthIdentifier();
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
