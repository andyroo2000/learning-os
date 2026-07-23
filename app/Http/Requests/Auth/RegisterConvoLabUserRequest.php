<?php

namespace App\Http\Requests\Auth;

use App\Http\Support\ConvoLabProxyAuthorization;

final class RegisterConvoLabUserRequest extends ConvoLabSignupRequest
{
    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'auth:signup');
    }
}
