<?php

namespace App\Http\Requests\FeatureFlags;

use App\Domain\FeatureFlags\Support\AudienceInput;

class SaveAccessGroupRequest extends SaveFeatureAudienceRequest
{
    public function rules(): array
    {
        return AudienceInput::rules(true);
    }
}
