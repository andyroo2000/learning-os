<?php

namespace App\Http\Requests\Auth;

use App\Http\Support\ConvoLabProxyAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class ResolveConvoLabGoogleIdentityRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['providerId', 'email', 'name', 'avatarUrl'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $this->merge([$field => trim($value)]);
            }
        }

        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => Str::lower($email)]);
        }
    }

    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'auth:oauth');
    }

    public function rules(): array
    {
        return [
            'providerId' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'emailVerified' => ['required', 'boolean'],
            'name' => ['required', 'string', 'max:255'],
            'avatarUrl' => ['nullable', 'string', 'url:http,https', 'max:2048'],
        ];
    }
}
