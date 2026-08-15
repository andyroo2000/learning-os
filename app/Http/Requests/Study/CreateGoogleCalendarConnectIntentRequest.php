<?php

namespace App\Http\Requests\Study;

use Illuminate\Foundation\Http\FormRequest;

final class CreateGoogleCalendarConnectIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['completionTarget' => ['required', 'string', 'in:ios,web']];
    }

    public function completionTarget(): string
    {
        return (string) $this->validated('completionTarget');
    }
}
