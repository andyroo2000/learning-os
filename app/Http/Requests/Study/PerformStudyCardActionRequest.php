<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Actions\SetCardDueAction;
use App\Domain\Flashcards\Models\Card;
use App\Support\DateTime\StrictIsoDateTime;
use App\Support\Identifiers\CanonicalUlid;
use Exception;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PerformStudyCardActionRequest extends FormRequest
{
    private ?Card $studyCard = null;

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['action', 'mode', 'dueAt', 'timeZone'] as $key) {
            $value = $this->input($key);

            // Leave non-string values untouched so validation reports type errors instead of coercing them.
            if (is_string($value)) {
                $normalized[$key] = trim($value);
            }
        }

        foreach (['action', 'mode'] as $key) {
            if (isset($normalized[$key])) {
                $normalized[$key] = strtolower($normalized[$key]);
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function authorize(): bool
    {
        $user = $this->user();
        $cardId = $this->route('cardId');

        if ($user !== null && is_string($cardId)) {
            $this->studyCard = Card::query()
                ->ownedByActiveDeck((int) $user->id)
                ->where('cards.id', CanonicalUlid::normalize($cardId))
                ->first();

            if ($this->studyCard === null) {
                throw new NotFoundHttpException('Study card not found.');
            }
        }

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['set_due', 'suspend', 'unsuspend', 'forget'])],
            'mode' => [
                'required_if:action,set_due',
                'exclude_unless:action,set_due',
                'string',
                Rule::in(['now', 'tomorrow', 'custom_date']),
            ],
            'dueAt' => [
                'exclude_unless:action,set_due',
                'required_if:mode,custom_date',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! StrictIsoDateTime::matches($value)) {
                        $fail('dueAt must be a valid ISO-8601 datetime for custom_date.');

                        return;
                    }

                    try {
                        $dueAt = Carbon::parse($value);
                    } catch (Exception) {
                        $fail('dueAt must be a valid ISO-8601 datetime for custom_date.');

                        return;
                    }

                    if ($dueAt->greaterThan(now()->addYears(SetCardDueAction::MAX_FUTURE_YEARS))) {
                        $fail('dueAt must be within 10 years.');
                    }
                },
            ],
            'timeZone' => [
                'required_if:mode,tomorrow',
                'nullable',
                'string',
                'timezone',
            ],
            // currentOverview is accepted for ConvoLab request compatibility; controllers recompute overview.
            'currentOverview' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.in' => 'action must be suspend, unsuspend, forget, or set_due.',
            'mode.in' => 'mode must be now, tomorrow, or custom_date for set_due.',
            'mode.required_if' => 'mode must be now, tomorrow, or custom_date for set_due.',
            'dueAt.date' => 'dueAt must be a valid ISO-8601 datetime for custom_date.',
            'dueAt.required_if' => 'dueAt must be a valid ISO-8601 datetime for custom_date.',
            'timeZone.required_if' => 'timeZone must be a valid IANA timezone for tomorrow.',
            'timeZone.timezone' => 'timeZone must be a valid IANA timezone for tomorrow.',
        ];
    }

    public function action(): string
    {
        return (string) $this->validated()['action'];
    }

    public function mode(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('mode', $validated)) {
            return null;
        }

        return (string) $validated['mode'];
    }

    public function dueAt(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('dueAt', $validated)) {
            return null;
        }

        return (string) $validated['dueAt'];
    }

    public function timeZone(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('timeZone', $validated)) {
            return null;
        }

        return $validated['timeZone'] === null ? null : (string) $validated['timeZone'];
    }

    public function studyCard(): Card
    {
        if ($this->studyCard === null) {
            throw new NotFoundHttpException('Study card not found.');
        }

        return $this->studyCard;
    }
}
