<?php

namespace App\Http\Requests\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Sync\Values\SyncMetadata;
use App\Http\Requests\Concerns\NormalizesUlidInput;
use App\Http\Requests\Concerns\ValidatesStrictIsoDateTime;
use App\Http\Support\AuthenticatedUser;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreCardReviewEventBatchRequest extends FormRequest
{
    use NormalizesUlidInput;
    use ValidatesStrictIsoDateTime;

    public function authorize(): bool
    {
        // Ownership is validated per card_id so sync clients can map failures to events.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $events = $this->input('events');

        if (! is_array($events)) {
            return;
        }

        $normalizedEvents = [];

        foreach ($events as $index => $event) {
            if (! is_array($event)) {
                $normalizedEvents[$index] = $event;

                continue;
            }

            foreach (['id', 'card_id'] as $key) {
                if (array_key_exists($key, $event)) {
                    $event[$key] = $this->normalizeUlidValue($event[$key]);
                }
            }

            foreach (['rating', 'reviewed_at', 'client_event_id', 'device_id', 'client_created_at'] as $key) {
                if (array_key_exists($key, $event) && is_string($event[$key])) {
                    $event[$key] = trim($event[$key]);
                }
            }

            $normalizedEvents[$index] = $event;
        }

        $this->merge(['events' => $normalizedEvents]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:500'],
            'events.*.id' => ['nullable', 'ulid'],
            'events.*.card_id' => ['required', 'ulid'],
            'events.*.rating' => ['required', Rule::enum(CardReviewRating::class)],
            'events.*.reviewed_at' => [
                'required',
                'bail',
                'string',
                $this->strictIsoDateTimeRule('reviewed_at must be a valid ISO-8601 datetime.'),
            ],
            'events.*.duration_ms' => ['nullable', 'integer', 'min:0', 'max:'.ReviewCardData::MAX_DURATION_MS],
            'events.*.client_event_id' => ['required', 'string', 'max:'.SyncMetadata::MAX_CLIENT_EVENT_ID_LENGTH],
            'events.*.device_id' => ['required', 'string', 'max:'.SyncMetadata::MAX_DEVICE_ID_LENGTH],
            'events.*.client_created_at' => [
                'required',
                'bail',
                'string',
                $this->strictIsoDateTimeRule('client_created_at must be a valid ISO-8601 datetime.'),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function reviewEvents(): array
    {
        return collect($this->validated('events'))
            ->map(function (array $event): array {
                if (array_key_exists('duration_ms', $event) && $event['duration_ms'] !== null) {
                    $event['duration_ms'] = (int) $event['duration_ms'];
                }

                return $event;
            })
            ->all();
    }

    protected function passedValidation(): void
    {
        // Batch sync needs per-item ownership errors and rejects atomically on any mismatch.
        $events = collect($this->reviewEvents());
        $cardIds = $events->pluck('card_id')->unique()->values();

        // Eloquent applies Card and Deck SoftDeletes scopes here.
        $candidateCardIds = $cardIds
            ->flatMap(fn (string $cardId): array => CanonicalUlid::databaseCandidates($cardId))
            ->unique()
            ->values();

        $visibleCardIds = Card::query()
            ->whereKey($candidateCardIds)
            ->whereHas('deck', fn ($query) => $query->where('user_id', AuthenticatedUser::id($this)))
            ->pluck('id')
            ->map(fn (string $cardId): string => CanonicalUlid::normalize($cardId))
            ->all();

        $visibleCardIds = array_flip($visibleCardIds);
        $errors = [];

        foreach ($events as $index => $event) {
            if (! isset($visibleCardIds[$event['card_id']])) {
                $errors["events.{$index}.card_id"] = ['The selected card id is invalid.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
