<?php

namespace App\Http\Requests\Content;

final class GenerateContentCourseRequest extends MutateContentCourseGenerationRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->exists('clientRequestId')) {
            $clientRequestId = $this->input('clientRequestId');
            $this->merge([
                'clientRequestId' => is_string($clientRequestId)
                    ? strtolower(trim($clientRequestId))
                    : $clientRequestId,
            ]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'clientRequestId' => ['sometimes', 'required', 'uuid'],
        ];
    }

    public function clientRequestId(): ?string
    {
        $validated = $this->validated();

        return $validated['clientRequestId'] ?? null;
    }

    protected function requiresVerifiedEmail(): bool
    {
        return true;
    }

    protected function blocksDemoMutation(): bool
    {
        return true;
    }
}
