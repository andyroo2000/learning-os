<?php

namespace App\Http\Requests\Content;

use App\Http\Support\ConvoLabProxyAuthorization;
use Illuminate\Foundation\Http\FormRequest;

abstract class ConvoLabContentWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'content:write');
    }
}
