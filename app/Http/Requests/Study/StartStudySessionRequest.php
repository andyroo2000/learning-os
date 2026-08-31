<?php

namespace App\Http\Requests\Study;

use App\Http\Requests\Concerns\AcceptsStudyTimeZone;
use App\Http\Requests\Concerns\FiltersByStudyScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StartStudySessionRequest extends FormRequest
{
    use AcceptsStudyTimeZone;
    use FiltersByStudyScope;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareStudyScopeFiltersForValidation();
        $this->prepareStudyTimeZoneForValidation();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...$this->studyScopeRules(),
            ...$this->studyTimeZoneRules(),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            ...$this->studyScopeAfterValidationCallbacks(),
            ...$this->studyTimeZoneAfterValidationCallbacks(),
        ];
    }
}
