<?php

namespace App\Http\Requests\Flashcards;

use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateDeckRequest extends FormRequest
{
    private const TRIMMED_INPUT_KEYS = ['name', 'description'];

    public function authorize(): bool
    {
        /** @var Deck $deck */
        $deck = $this->route('deck');

        // Throw via Gate so DeckPolicy's 404 denial is preserved; returning false here would become a 403.
        Gate::authorize('update', $deck);

        return true;
    }

    protected function prepareForValidation(): void
    {
        // Trim before validation so whitespace-only input does not depend on global middleware.
        // UpdateDeckData trims again so non-HTTP callers get the same domain invariants.
        $input = [];

        foreach (self::TRIMMED_INPUT_KEYS as $key) {
            // Preserve missing keys so required/present rules can report omitted fields.
            if ($this->exists($key)) {
                $input[$key] = $this->trimStringInput($key);
            }
        }

        $this->merge($input);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Deck updates currently use a complete mutable payload, matching card updates.
        // This keeps nullable fields explicit until sync conflict semantics are introduced.
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['present', 'nullable', 'string', 'max:10000'],
        ];
    }

    private function trimStringInput(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : $value;
    }
}
