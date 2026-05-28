<?php

namespace App\Http\Requests\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCardReviewEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is validated on card_id so clients get a field-specific 422.
        return true;
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
                // Single events can use Rule::exists; batches validate separately for per-index errors.
                Rule::exists(Card::class, 'id')->whereIn(
                    'deck_id',
                    Deck::query()->select('id')->where('user_id', $userId),
                ),
            ],
            'rating' => ['required', Rule::enum(CardReviewRating::class)],
            'reviewed_at' => ['required', 'date'],
            'client_event_id' => ['nullable', 'string', 'required_with:device_id,client_created_at'],
            'device_id' => ['nullable', 'string', 'required_with:client_event_id,client_created_at'],
            'client_created_at' => ['nullable', 'date', 'required_with:client_event_id,device_id'],
        ];
    }
}
