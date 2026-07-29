<?php

namespace App\Http\Requests\Study;

use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;

class ShowStudyActivityAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'timezone' => ['required', 'string', 'timezone:all'],
            'weekStartsOn' => ['required', 'integer', 'between:1,7'],
        ];
    }

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone($this->validated('timezone'));
    }

    public function weekStartsOn(): int
    {
        return (int) $this->validated('weekStartsOn');
    }
}
