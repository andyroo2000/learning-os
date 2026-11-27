<?php

namespace App\Http\Requests\FeatureFlags;

use App\Domain\FeatureFlags\Support\AudienceInput;
use App\Http\Requests\Admin\ConvoLabAdminReadRequest;

class SaveFeatureAudienceRequest extends ConvoLabAdminReadRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(AudienceInput::normalize($this->all()));
    }

    public function rules(): array
    {
        return AudienceInput::rules(false);
    }
}
