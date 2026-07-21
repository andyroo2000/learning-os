<?php

namespace App\Http\Requests\Content;

use App\Http\Support\ConvoLabProxyAuthorization;
use Illuminate\Foundation\Http\FormRequest;

abstract class ConvoLabContentWriteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $convoLabUserId = $this->header('X-Convo-Lab-User-Id');

        $this->merge([
            'convolabUserId' => is_string($convoLabUserId)
                ? strtolower(trim($convoLabUserId))
                : $convoLabUserId,
        ]);
    }

    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'content:write');
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
