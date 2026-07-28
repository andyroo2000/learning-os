<?php

namespace App\Http\Requests\Study;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListStudyActivitySessionsRequest extends FormRequest
{
    private const ISO_8601_TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'string', 'regex:'.self::ISO_8601_TIMESTAMP, 'date'],
            'to' => ['required', 'string', 'regex:'.self::ISO_8601_TIMESTAMP, 'date', 'after:from'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $data = $this->validated();
                $from = CarbonImmutable::parse($data['from']);
                $to = CarbonImmutable::parse($data['to']);
                if ($from->diffInRealSeconds($to) > 93 * 86_400) {
                    $validator->errors()->add('to', 'The requested study activity range may not exceed 93 days.');
                }
            },
        ];
    }
}
