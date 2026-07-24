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
            'dialoguesEnabled' => ['sometimes', 'required', 'boolean'],
            'scriptsEnabled' => ['sometimes', 'required', 'boolean'],
            'audioCourseEnabled' => ['sometimes', 'required', 'boolean'],
            'flashcardsEnabled' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
