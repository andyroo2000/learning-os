<?php

namespace App\Http\Requests\Readings;

use App\Http\Support\ConvoLabRequestIdentity;
use Illuminate\Foundation\Http\FormRequest;

class ReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ConvoLabRequestIdentity::allowsFirstPartySession($this);
    }

    public function rules(): array
    {
        return ['viewAs' => ['prohibited']];
    }
}
