<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RequestConvoLabBetaAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc,strict', 'max:254'],
            'devices' => ['required', 'array', 'list', 'min:1', 'max:3'],
            'devices.*' => ['required', 'string', 'distinct:strict', Rule::in(['Web', 'iOS', 'Android'])],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
