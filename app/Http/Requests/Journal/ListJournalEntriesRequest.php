<?php

namespace App\Http\Requests\Journal;

use Illuminate\Foundation\Http\FormRequest;

class ListJournalEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'required', 'string', 'max:512'],
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:50'],
        ];
    }
}
