<?php

namespace App\Http\Requests\Study;

use Illuminate\Foundation\Http\FormRequest;

class ConnectWaniKaniRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $apiToken = $this->input('apiToken');
        if (is_string($apiToken)) {
            $this->merge(['apiToken' => trim($apiToken)]);
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['apiToken' => ['required', 'string', 'min:1', 'max:512']];
    }

    public function apiToken(): string
    {
        return (string) $this->validated('apiToken');
    }
}
