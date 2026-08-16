<?php

namespace App\Http\Requests\Study;

use App\Domain\Calendar\Data\GoogleCalendarSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateGoogleCalendarSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'calendarIds' => ['required', 'array', 'min:1', 'max:25'],
            'calendarIds.*' => ['required', 'string', 'max:1024'],
            'titleMatchTerms' => ['required', 'array', 'min:1', 'max:50'],
            'titleMatchTerms.*' => ['required', 'string', 'max:100'],
            'syncEnabled' => ['required', 'boolean:strict'],
        ];
    }

    public function settings(): GoogleCalendarSettings
    {
        $validated = $this->validated();

        return GoogleCalendarSettings::make($validated['calendarIds'], $validated['titleMatchTerms'], $validated['syncEnabled']);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['calendarIds', 'titleMatchTerms'] as $field) {
                $value = $validator->getData()[$field] ?? null;
                if (is_array($value) && ! array_is_list($value)) {
                    $validator->errors()->add($field, "The $field field must be a list.");
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        foreach (['calendarIds', 'titleMatchTerms'] as $field) {
            $value = $this->input($field);
            if (is_array($value)) {
                $length = $field === 'calendarIds' ? 1024 : 100;
                $this->merge([$field => array_map(static fn (mixed $item): mixed => GoogleCalendarSettings::trimInput($item, $length), $value)]);
            }
        }
    }
}
