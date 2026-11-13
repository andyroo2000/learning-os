<?php

namespace App\Http\Requests\Journal;

use App\Domain\Journal\Support\JournalConversationInput;
use Illuminate\Foundation\Http\FormRequest;

class CreateJournalConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(JournalConversationInput::normalize($this->all()));
    }

    public function rules(): array
    {
        return JournalConversationInput::rules();
    }
}
