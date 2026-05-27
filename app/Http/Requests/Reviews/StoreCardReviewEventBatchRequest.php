<?php

namespace App\Http\Requests\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCardReviewEventBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'events.*.client_event_id' => ['required', 'string'],
            'events.*.device_id' => ['required', 'string'],
            'events.*.client_created_at' => ['required', 'date'],
        ];
    }
}
