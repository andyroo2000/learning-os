<?php

namespace App\Http\Requests\Admin;

use App\Http\Support\ConvoLabProxyAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class ConvoLabAdminReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'admin:read');
    }

    public function rules(): array
    {
        return [];
    }
}
