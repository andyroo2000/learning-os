<?php

namespace App\Http\Requests\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCardReviewEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        // TODO(#21): Replace this with an ownership policy when API auth lands.
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'id' => ['nullable', 'ulid'],
            'card_id' => ['required', 'ulid', Rule::exists('cards', 'id')],
            'rating' => ['required', Rule::enum(CardReviewRating::class)],
            'reviewed_at' => ['required', 'date'],
            'client_event_id' => ['nullable', 'string', 'required_with:device_id,client_created_at'],
            'device_id' => ['nullable', 'string', 'required_with:client_event_id,client_created_at'],
            'client_created_at' => ['nullable', 'date', 'required_with:client_event_id,device_id'],
        ];
    }
}
