<?php

namespace App\Http\Requests\FeatureFlags;

use Illuminate\Foundation\Http\FormRequest;
use Laravel\Sanctum\PersonalAccessToken;

class UpdateFeatureFlagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $token = $this->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || $token->name !== 'convolab-proxy') {
            return false;
        }

        // Accept the deployed proxy's legacy scope until the next ConvoLab token rotation.
        return in_array('feature-flags:write', $token->abilities, true)
            || in_array('study:write', $token->abilities, true);
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
