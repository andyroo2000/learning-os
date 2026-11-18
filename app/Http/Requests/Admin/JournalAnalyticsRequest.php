<?php

namespace App\Http\Requests\Admin;

final class JournalAnalyticsRequest extends ConvoLabAdminReadRequest
{
    public function rules(): array
    {
        return ['period' => ['sometimes', 'required', 'string', 'in:daily,weekly,monthly,yearly'],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100000']];
    }
}
