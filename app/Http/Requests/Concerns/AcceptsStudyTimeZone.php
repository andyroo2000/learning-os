<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

trait AcceptsStudyTimeZone
{
    use NormalizesStringInputs;

    protected function prepareStudyTimeZoneForValidation(): void
    {
        $this->mergeNormalizedStringInputs(['timeZone', 'time_zone']);
    }

    /**
     * @return array<string, list<string>>
     */
    protected function studyTimeZoneRules(): array
    {
        $rules = ['sometimes', 'nullable', 'string', 'timezone'];

        return [
            'timeZone' => $rules,
            'time_zone' => $rules,
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    protected function studyTimeZoneAfterValidationCallbacks(): array
    {
        return [
            static function (Validator $validator): void {
                if ($validator->errors()->hasAny(['timeZone', 'time_zone'])) {
                    return;
                }

                $validated = $validator->safe()->all();

                if (
                    array_key_exists('timeZone', $validated)
                    && array_key_exists('time_zone', $validated)
                    && $validated['timeZone'] !== $validated['time_zone']
                ) {
                    $validator->errors()->add(
                        'timeZone',
                        'The timeZone and time_zone values must match when both are provided.',
                    );
                }
            },
        ];
    }

    public function timeZone(): ?string
    {
        $validated = $this->validated();

        if (array_key_exists('timeZone', $validated)) {
            return $validated['timeZone'] === null ? null : (string) $validated['timeZone'];
        }

        if (array_key_exists('time_zone', $validated)) {
            return $validated['time_zone'] === null ? null : (string) $validated['time_zone'];
        }

        return null;
    }
}
