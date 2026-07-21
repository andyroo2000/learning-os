<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;

final class ConvoLabCurrentUserResource extends ConvoLabAccountResource
{
    public function toArray(Request $request): array
    {
        $account = parent::toArray($request);
        $account['seenSampleContentGuide'] = $this->seen_sample_content_guide;
        $account['seenCustomContentGuide'] = $this->seen_custom_content_guide;

        return $account;
    }
}
