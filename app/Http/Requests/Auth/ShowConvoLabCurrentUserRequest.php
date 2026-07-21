<?php

namespace App\Http\Requests\Auth;

use App\Http\Support\ConvoLabProxyAuthorization;
use Illuminate\Foundation\Http\FormRequest;

final class ShowConvoLabCurrentUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $userId = $this->header('X-Convo-Lab-User-Id');
        $this->merge([
            'convolabUserId' => is_string($userId) ? strtolower(trim($userId)) : $userId,
        ]);
    }

    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'auth:read');
    }

    public function rules(): array
    {
        return ['convolabUserId' => ['required', 'uuid']];
    }

    public function convoLabUserId(): string
    {
        return $this->validated('convolabUserId');
    }
}
