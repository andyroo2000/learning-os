<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

final class ListBetaRequestsRequest extends ConvoLabAdminReadRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(['pending', 'invited', 'all'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
