<?php

namespace App\Http\Requests\Study;

use Illuminate\Foundation\Http\FormRequest;

class StartStudySessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'time_zone' => [
                'sometimes',
                'nullable',
                'string',
                'timezone',
            ],
        ];
    }
}
