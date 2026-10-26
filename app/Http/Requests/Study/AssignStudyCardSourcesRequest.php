<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Support\CardBookSource;
use Illuminate\Foundation\Http\FormRequest;

class AssignStudyCardSourcesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cardIds' => ['required', 'array', 'min:1', 'max:100'],
            'cardIds.*' => ['required', 'string', 'ulid'],
            ...CardBookSource::rules('bookSource'),
            'bookSource' => ['present', 'nullable', 'array:pileId,chapterId,position,reference'],
        ];
    }
}
