<?php

namespace App\Http\Requests\Journal;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JournalAudioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('analysisId'))) {
            $this->merge(['analysisId' => CanonicalUlid::normalize($this->input('analysisId'))]);
        }
    }

    public function rules(): array
    {
        return [
            'analysisId' => ['required', 'ulid'],
            'voiceId' => ['required', 'string', Rule::in(array_keys(config('reading_voices')))],
        ];
    }
}
