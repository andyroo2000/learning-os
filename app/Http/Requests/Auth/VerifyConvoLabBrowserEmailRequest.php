<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class VerifyConvoLabBrowserEmailRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $token = $this->input('token');
        if (is_string($token)) {
            $this->merge(['token' => Str::lower(trim($token))]);
        }
    }

    public function authorize(): bool
    {
        return $this->hasSession() && $this->attributes->get('sanctum') === true;
    }

    public function rules(): array
    {
        return ['token' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/']];
    }

    public function token(): string
    {
        return $this->validated('token');
    }
}
