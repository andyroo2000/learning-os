<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\NormalizesConvoLabUserId;
use App\Http\Support\ConvoLabRequestIdentity;
use Illuminate\Foundation\Http\FormRequest;

final class DisconnectConvoLabGoogleIdentityRequest extends FormRequest
{
    use NormalizesConvoLabUserId;

    public function authorize(): bool
    {
        return ConvoLabRequestIdentity::allows($this, 'auth:oauth');
    }

    public function rules(): array
    {
        return ['convolabUserId' => ['required', 'uuid']];
    }
}
