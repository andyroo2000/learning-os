<?php

namespace App\Http\Requests\Readings;

final class ReadingProgressRequest extends ReadingRequest
{
    public function rules(): array
    {
        if (! $this->isMethod('PUT')) {
            return parent::rules();
        }

        return [...parent::rules(), 'eventId' => ['required', 'uuid'],
            'readingRevision' => ['required', 'integer', 'min:1'],
            'pageNumber' => ['required', 'integer', 'min:1', 'max:99999'],
            'sentenceIndex' => ['present', 'nullable', 'integer', 'min:0', 'max:4999'],
            'readAt' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()]];
    }
}
