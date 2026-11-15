<?php

namespace App\Http\Requests\Journal;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Foundation\Http\FormRequest;

class SubmitJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $id = $this->input('clarificationId');
        if (is_string($id)) {
            $this->merge(['clarificationId' => CanonicalUlid::normalize($id)]);
        }
    }

    public function rules(): array
    {
        return ['expectedVersion' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'clarificationId' => ['sometimes', 'required', 'ulid']];
    }
}
