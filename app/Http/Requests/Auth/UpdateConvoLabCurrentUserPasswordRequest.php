<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\NormalizesConvoLabUserId;
use App\Http\Support\ConvoLabRequestIdentity;

final class UpdateConvoLabCurrentUserPasswordRequest extends UpdateCurrentUserPasswordRequest
{
    use NormalizesConvoLabUserId;

    public function authorize(): bool
    {
        return ConvoLabRequestIdentity::allows($this, 'auth:write');
    }

    public function rules(): array
    {
        return [
            'convolabUserId' => ['required', 'uuid'],
            ...parent::rules(),
        ];
    }
}
