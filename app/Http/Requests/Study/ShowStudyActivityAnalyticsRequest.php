<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Enums\StudyActivityAnalyticsRange;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'ranges' => ['sometimes', 'array', 'min:1', 'max:5', 'list'],
            'ranges.*' => ['required', 'string', 'distinct', Rule::enum(StudyActivityAnalyticsRange::class)],
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

    /** @return list<StudyActivityAnalyticsRange>|null */
    public function ranges(): ?array
    {
        $ranges = $this->validated('ranges');

        return $ranges === null ? null : array_map(StudyActivityAnalyticsRange::from(...), $ranges);
    }

    public function adaptiveAllTime(): bool
    {
        return (bool) ($this->validated('adaptiveAllTime') ?? false);
    }
}
