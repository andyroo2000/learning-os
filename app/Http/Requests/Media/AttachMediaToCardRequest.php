<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class AttachMediaToCardRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'media_asset_id' => ['required', 'ulid'],
        ];
    }
}
