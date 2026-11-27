<?php

namespace App\Http\Requests\Learn;

use Illuminate\Foundation\Http\FormRequest;

class LearnTurnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['action' => ['required', 'in:message,answer,clarify,next,previous,restart,review,understand,ready,workbook,reflect'],
            'requestId' => ['required', 'uuid'], 'expectedVersion' => ['required', 'integer', 'min:1'],
            'text' => ['required_if:action,message,answer,clarify', 'nullable', 'string', 'max:3000', 'not_regex:/\x00/']];
    }

    public function turn(): array
    {
        $input = $this->validated();
        $input['expectedVersion'] = (int) $input['expectedVersion'];
        $input['requestId'] = strtolower($input['requestId']);

        return $input;
    }
}
