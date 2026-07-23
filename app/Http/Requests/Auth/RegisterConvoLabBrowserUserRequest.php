<?php

namespace App\Http\Requests\Auth;

final class RegisterConvoLabBrowserUserRequest extends ConvoLabSignupRequest
{
    public function authorize(): bool
    {
        return $this->hasSession() && $this->attributes->get('sanctum') === true;
    }
}
