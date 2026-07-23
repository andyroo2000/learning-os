<?php

namespace App\Http\Requests\Analytics;

use App\Domain\Analytics\Support\ToolAnalyticsProperties;
use App\Http\Support\ConvoLabProxyAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreToolAnalyticsEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'tools:analytics');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $safeToken = ['string', 'max:80', 'regex:/^[a-z0-9:_-]+$/i'];

        return [
            'tool' => ['required', ...$safeToken],
            'event' => ['required', ...$safeToken],
            'context' => ['required', 'string', Rule::in(['app', 'public'])],
            'mode' => ['sometimes', 'nullable', 'string', Rule::in(['fsrs', 'random'])],
            'sessionId' => ['sometimes', 'nullable', ...$safeToken],
            'properties' => ['sometimes', 'array', new ToolAnalyticsProperties],
        ];
    }
}
