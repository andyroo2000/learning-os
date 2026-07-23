<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

abstract class ConvoLabSignupRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['email', 'name', 'inviteCode'] as $field) {
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

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024', Password::defaults()],
            'name' => ['required', 'string', 'max:255'],
            'inviteCode' => ['required', 'string', 'max:20'],
        ];
    }
}
