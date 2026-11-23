<?php

namespace App\Http\Requests\Journal;

use App\Domain\Journal\Support\JournalDraftInput;
use Illuminate\Foundation\Http\FormRequest;

class SaveJournalDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->headers->has('X-Journal-Owner')) {
            return true;
        }

        // Browser drafts use the public account ID returned by /api/auth/me.
        $owner = $this->user()?->getAttribute('convolab_id');
        if (! is_string($owner) || $owner === '') {
            return false;
        }

        return $this->header('X-Journal-Owner') === $owner;
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
