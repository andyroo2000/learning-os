<?php

namespace App\Http\Requests\Study;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

class StoreStudyCardFromDraftRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $id = $this->input('id');

        if (is_string($id)) {
            $this->merge([
                'id' => CanonicalUlid::normalize($id),
            ]);
        }
    }

    public function authorize(): bool
    {
        if ($this->user() === null) {
            // Authentication middleware returns 401 first; keep this request invariant explicit
            // if the route middleware is ever changed.
            throw new AuthenticationException;
        }

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Draft commits require a client-generated card ID so retries do not duplicate cards.
            'id' => ['required', 'string', 'ulid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.required' => 'Card ID is required.',
            'id.string' => 'Card ID must be a string.',
            'id.ulid' => 'Card ID must be a valid ULID.',
        ];
    }

    public function id(): string
    {
        $id = $this->validated('id');

        if (! is_string($id)) {
            throw new LogicException('id called after validation failed to require a string card ID.');
        }

        return $id;
    }
}
