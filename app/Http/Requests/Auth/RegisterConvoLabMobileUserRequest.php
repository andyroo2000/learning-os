<?php

namespace App\Http\Requests\Auth;

final class RegisterConvoLabMobileUserRequest extends ConvoLabSignupRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $deviceName = $this->input('device_name');
        if (is_string($deviceName)) {
            $this->merge(['device_name' => trim($deviceName)]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }
}
