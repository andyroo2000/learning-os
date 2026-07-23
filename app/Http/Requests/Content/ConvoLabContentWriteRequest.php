<?php

namespace App\Http\Requests\Content;

use App\Http\Support\ConvoLabRequestIdentity;

abstract class ConvoLabContentWriteRequest extends ConvoLabContentUserRequest
{
    public function authorize(): bool
    {
        return ConvoLabRequestIdentity::allows($this, 'content:write');
    }
}
