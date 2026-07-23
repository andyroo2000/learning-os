<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class SendConvoLabBrowserVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->hasSession() && $this->attributes->get('sanctum') === true;
    }

    public function rules(): array
    {
        return [];
    }
}
