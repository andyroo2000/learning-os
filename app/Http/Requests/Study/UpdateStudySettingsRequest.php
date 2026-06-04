<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Models\StudySettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStudySettingsRequest extends FormRequest
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
            'new_cards_per_day' => [
                'required',
                'integer',
                'min:0',
                'max:'.StudySettings::MAX_NEW_CARDS_PER_DAY,
            ],
        ];
    }
}
