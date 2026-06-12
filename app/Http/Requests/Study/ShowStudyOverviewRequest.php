<?php

namespace App\Http\Requests\Study;

use App\Http\Requests\Concerns\FiltersByStudyScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShowStudyOverviewRequest extends FormRequest
{
    use FiltersByStudyScope;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareStudyScopeFiltersForValidation();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...$this->studyScopeRules(),
            'time_zone' => [
                'sometimes',
                'nullable',
                'string',
                'timezone',
            ],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return $this->studyScopeAfterValidationCallbacks();
    }
}
