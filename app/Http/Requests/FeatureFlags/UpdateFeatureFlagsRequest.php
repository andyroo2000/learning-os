<?php

namespace App\Http\Requests\FeatureFlags;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class UpdateFeatureFlagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $token = $this->user()?->currentAccessToken();
        $proxyUserEmail = config('services.convolab.proxy_user_email');

        if (
            ! $token instanceof PersonalAccessToken
            || $token->name !== 'convolab-proxy'
            || ! is_string($proxyUserEmail)
            || $proxyUserEmail === ''
            || ! hash_equals(
                Str::lower(trim($proxyUserEmail)),
                Str::lower(trim((string) $this->user()?->email)),
            )
        ) {
            return false;
        }

        return in_array('feature-flags:write', $token->abilities, true);
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
