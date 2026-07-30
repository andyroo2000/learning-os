<?php

namespace App\Http\Requests\Study;

use Carbon\CarbonImmutable;
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
            'anchorDate' => ['sometimes', 'string', 'date_format:Y-m-d'],
            'adaptiveAllTime' => ['sometimes', 'boolean'],
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

    public function anchor(): ?CarbonImmutable
    {
        $anchorDate = $this->validated('anchorDate');
        if ($anchorDate === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            (string) $anchorDate,
            $this->timezone(),
        );
    }

    public function adaptiveAllTime(): bool
    {
        return (bool) ($this->validated('adaptiveAllTime') ?? false);
    }
}
