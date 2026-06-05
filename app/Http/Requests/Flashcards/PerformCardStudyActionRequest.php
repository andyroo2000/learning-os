<?php

namespace App\Http\Requests\Flashcards;

use App\Domain\Flashcards\Actions\SetCardDueAction;
use App\Domain\Flashcards\Models\Card;
use App\Http\Requests\Concerns\FiltersByDeckId;
use App\Support\DateTime\StrictIsoDateTime;
use App\Support\Identifiers\CanonicalUlid;
use Exception;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PerformCardStudyActionRequest extends FormRequest
{
    use FiltersByDeckId;

    public function authorize(): bool
    {
        /** @var Card $card */
        $card = $this->route('card');

        Gate::authorize('update', $card);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if (is_string($this->input('action'))) {
            $data['action'] = strtolower(trim($this->input('action')));
        }

        if (is_string($this->input('mode'))) {
            $data['mode'] = strtolower(trim($this->input('mode')));
        }

        $this->prepareDeckIdForValidation();

        if ($data !== []) {
            $this->merge($data);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => [
                'required',
                'string',
                Rule::in(['set_due', 'suspend', 'unsuspend', 'forget']),
            ],
            'mode' => [
                'required_if:action,set_due',
                'exclude_unless:action,set_due',
                'string',
                Rule::in(['now', 'tomorrow', 'custom_date']),
            ],
            'due_at' => [
                'exclude_unless:mode,custom_date',
                'required_if:mode,custom_date',
                'string',
                'date',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! StrictIsoDateTime::matches($value)) {
                        $fail('due_at must be a valid ISO-8601 datetime for custom_date.');

                        return;
                    }

                    try {
                        $dueAt = Carbon::parse($value);
                    } catch (Exception) {
                        $fail('due_at must be a valid ISO-8601 datetime for custom_date.');

                        return;
                    }

                    if ($dueAt->greaterThan(now()->addYears(SetCardDueAction::MAX_FUTURE_YEARS))) {
                        $fail('due_at must be within 10 years.');
                    }
                },
            ],
            'time_zone' => [
                'required_if:mode,tomorrow',
                'nullable',
                'string',
                'timezone',
            ],
            'deck_id' => ['sometimes', 'filled', 'ulid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('deck_id')) {
                return;
            }

            $deckId = $validator->getData()['deck_id'] ?? null;

            if (! is_string($deckId)) {
                return;
            }

            /** @var Card $card */
            $card = $this->route('card');

            if (CanonicalUlid::normalize((string) $card->deck_id) !== $deckId) {
                $validator->errors()->add('deck_id', "The deck_id must match the card's deck.");
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.in' => 'action must be set_due, suspend, unsuspend, or forget.',
            'mode.in' => 'mode must be now, tomorrow, or custom_date for set_due.',
            'mode.required_if' => 'mode must be now, tomorrow, or custom_date for set_due.',
            'due_at.date' => 'due_at must be a valid ISO-8601 datetime for custom_date.',
            'due_at.required_if' => 'due_at must be a valid ISO-8601 datetime for custom_date.',
            'time_zone.required_if' => 'time_zone must be a valid IANA timezone for tomorrow.',
            'time_zone.timezone' => 'time_zone must be a valid IANA timezone for tomorrow.',
        ];
    }
}
