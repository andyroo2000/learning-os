<?php

namespace App\Http\Requests\Admin;

use App\Http\Support\ConvoLabAdminAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class ConvoLabAdminReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ConvoLabAdminAuthorization::allows($this, 'admin:read');
    }

    public function rules(): array
    {
        return [];
    }
}
