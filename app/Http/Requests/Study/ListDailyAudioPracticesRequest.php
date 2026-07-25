<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Actions\ListDailyAudioPracticesAction;
use Illuminate\Foundation\Http\FormRequest;

class ListDailyAudioPracticesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'paginated' => ['sometimes', 'filled', 'boolean'],
            'cursor' => ['sometimes', 'filled', 'integer', 'min:0'],
            'limit' => [
                'sometimes',
                'filled',
                'integer',
                'min:1',
                'max:'.ListDailyAudioPracticesAction::PAGE_SIZE_MAX,
            ],
        ];
    }

    public function paginated(): bool
    {
        return (bool) ($this->validated()['paginated'] ?? false);
    }

    public function cursor(): int
    {
        return (int) ($this->validated()['cursor'] ?? 0);
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? ListDailyAudioPracticesAction::RECENT_LIMIT);
    }
}
