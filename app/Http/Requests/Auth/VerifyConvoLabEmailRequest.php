<?php

namespace App\Http\Requests\Auth;

use App\Http\Support\ConvoLabProxyAuthorization;
use Illuminate\Foundation\Http\FormRequest;

final class VerifyConvoLabEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'auth:verification');
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
