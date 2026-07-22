<?php

namespace App\Http\Requests\Auth;

use App\Domain\Auth\Data\UpdateConvoLabProfileData;
use App\Http\Requests\Auth\Concerns\NormalizesConvoLabUserId;
use App\Http\Support\ConvoLabProxyAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateConvoLabCurrentUserRequest extends FormRequest
{
    use NormalizesConvoLabUserId;

    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'auth:write');
    }

    public function rules(): array
    {
        return [
            'convolabUserId' => ['required', 'uuid'],
            'displayName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avatarColor' => ['sometimes', 'string', Rule::in(['indigo', 'teal', 'purple', 'pink', 'emerald', 'amber', 'rose', 'cyan'])],
            'avatarUrl' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'preferredStudyLanguage' => ['sometimes', Rule::in(['ja'])],
            'preferredNativeLanguage' => ['sometimes', Rule::in(['en'])],
            'proficiencyLevel' => ['required_if:onboardingCompleted,true', Rule::in(['N5', 'N4', 'N3', 'N2', 'N1'])],
            'onboardingCompleted' => ['sometimes', 'boolean'],
            'seenSampleContentGuide' => ['sometimes', 'boolean'],
            'seenCustomContentGuide' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys(UpdateConvoLabProfileData::FIELD_MAP) as $field) {
                if ($this->exists($field)) {
                    return;
                }
            }

            $validator->errors()->add('profile', 'At least one profile field is required.');
        }];
    }

    public function profileData(): UpdateConvoLabProfileData
    {
        $validated = $this->validated();
        unset($validated['convolabUserId']);

        return UpdateConvoLabProfileData::fromValidated($validated);
    }
}
