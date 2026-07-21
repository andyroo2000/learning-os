<?php

namespace App\Http\Requests\Content;

use App\Http\Support\ConvoLabProxyAuthorization;

abstract class ConvoLabContentWriteRequest extends ConvoLabContentUserRequest
{
    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'content:write');
    }
}
