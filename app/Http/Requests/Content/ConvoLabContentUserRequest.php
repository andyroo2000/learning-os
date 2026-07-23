<?php

namespace App\Http\Requests\Content;

use App\Http\Support\ConvoLabRequestIdentity;
use Illuminate\Foundation\Http\FormRequest;

abstract class ConvoLabContentUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'convolabUserId' => ConvoLabRequestIdentity::userId($this),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    protected function convoLabUserIdRules(): array
    {
        return ['convolabUserId' => ['required', 'uuid']];
    }

    public function convoLabUserId(): string
    {
        return $this->validated('convolabUserId');
    }
}
