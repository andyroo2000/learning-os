<?php

namespace App\Http\Requests\Study;

use App\Http\Requests\Concerns\FiltersByDeckId;
use Illuminate\Foundation\Http\FormRequest;

class ShowStudyOverviewRequest extends FormRequest
{
    use FiltersByDeckId;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareDeckIdForValidation();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'deck_id' => ['sometimes', 'filled', 'ulid'],
            'time_zone' => [
                'sometimes',
                'nullable',
                'string',
                'timezone',
            ],
        ];
    }
}
