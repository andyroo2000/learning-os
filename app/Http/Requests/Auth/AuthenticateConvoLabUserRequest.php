<?php

namespace App\Http\Requests\Auth;

use App\Http\Support\ConvoLabProxyAuthorization;

final class AuthenticateConvoLabUserRequest extends ConvoLabLoginRequest
{
    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'auth:login');
    }
}
