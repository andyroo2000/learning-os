<?php

namespace App\Http\Resources\Study;

use App\Domain\Study\Support\StudyClientCapabilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StudyClientCapabilities */
final class StudyClientCapabilitiesResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
