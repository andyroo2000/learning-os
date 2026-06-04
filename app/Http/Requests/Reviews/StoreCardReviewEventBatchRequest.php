<?php

namespace App\Http\Requests\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Sync\Values\SyncMetadata;
use App\Http\Requests\Concerns\NormalizesUlidInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreCardReviewEventBatchRequest extends FormRequest
{
    use NormalizesUlidInput;

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
            'events.*.reviewed_at' => ['required', 'date'],
            'events.*.client_event_id' => ['required', 'string', 'max:'.SyncMetadata::MAX_CLIENT_EVENT_ID_LENGTH],
            'events.*.device_id' => ['required', 'string', 'max:'.SyncMetadata::MAX_DEVICE_ID_LENGTH],
            'events.*.client_created_at' => ['required', 'date'],
        ];
    }

    protected function passedValidation(): void
    {
        // Batch sync needs per-item ownership errors and rejects atomically on any mismatch.
        $events = collect($this->validated('events'));
        $cardIds = $events->pluck('card_id')->unique()->values();

        // Eloquent applies Card and Deck SoftDeletes scopes here.
        $visibleCardIds = Card::query()
            ->whereKey($cardIds)
            ->whereHas('deck', fn ($query) => $query->where('user_id', $this->user()->id))
            ->pluck('id')
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
