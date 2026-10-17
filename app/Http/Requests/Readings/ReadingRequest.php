<?php

namespace App\Http\Requests\Readings;

use Illuminate\Foundation\Http\FormRequest;

class ReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['viewAs' => ['prohibited']];
    }
}
