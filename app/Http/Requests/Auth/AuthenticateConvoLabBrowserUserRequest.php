<?php

namespace App\Http\Requests\Auth;

final class AuthenticateConvoLabBrowserUserRequest extends ConvoLabLoginRequest
{
    public function authorize(): bool
    {
        return $this->hasSession() && $this->attributes->get('sanctum') === true;
    }
}
