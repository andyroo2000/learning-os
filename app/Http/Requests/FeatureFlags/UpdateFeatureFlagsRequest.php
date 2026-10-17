<?php

namespace App\Http\Requests\FeatureFlags;

use App\Http\Support\ConvoLabAdminAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFeatureFlagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ConvoLabAdminAuthorization::allows($this);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'flashcardsEnabled' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
