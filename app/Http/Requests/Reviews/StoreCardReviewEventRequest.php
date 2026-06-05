<?php

namespace App\Http\Requests\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Sync\Values\SyncMetadata;
use App\Http\Requests\Concerns\NormalizesUlidInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCardReviewEventRequest extends FormRequest
{
    use NormalizesUlidInput;

    public function authorize(): bool
    {
        // Ownership is validated on card_id so clients get a field-specific 422.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['id', 'card_id'] as $key) {
            $this->mergeNormalizedUlidInput($normalized, $key);
        }

        foreach (['rating', 'reviewed_at', 'client_event_id', 'device_id', 'client_created_at'] as $key) {
            $this->mergeTrimmedStringInput($normalized, $key);
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'id' => ['nullable', 'ulid'],
            'card_id' => [
                'required',
                'ulid',
                // Rule::exists bypasses SoftDeletes scopes, so the card guard is explicit.
                // Deck::query remains Eloquent-scoped, so deleted decks are excluded there.
                Rule::exists(Card::class, 'id')
                    ->whereNull('deleted_at')
                    ->whereIn(
                        'deck_id',
                        Deck::query()
                            ->select('id')
                            ->where('user_id', $userId),
                    ),
            ],
            'rating' => ['required', Rule::enum(CardReviewRating::class)],
            'reviewed_at' => ['required', 'date'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:'.ReviewCardData::MAX_DURATION_MS],
            'client_event_id' => ['nullable', 'string', 'max:'.SyncMetadata::MAX_CLIENT_EVENT_ID_LENGTH, 'required_with:device_id,client_created_at'],
            'device_id' => ['nullable', 'string', 'max:'.SyncMetadata::MAX_DEVICE_ID_LENGTH, 'required_with:client_event_id,client_created_at'],
            'client_created_at' => ['nullable', 'date', 'required_with:client_event_id,device_id'],
        ];
    }

    public function durationMs(): ?int
    {
        $validated = $this->validated();

        if (! array_key_exists('duration_ms', $validated) || $validated['duration_ms'] === null) {
            return null;
        }

        return (int) $validated['duration_ms'];
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function mergeTrimmedStringInput(array &$target, string $key): void
    {
        $value = $this->input($key);

        if (is_string($value)) {
            $target[$key] = trim($value);
        }
    }
}
